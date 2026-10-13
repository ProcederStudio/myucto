<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailAttachmentIngestRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry;
use MyInvoice\Service\Bank\StatementImporter;

/**
 * Načte z e-mailu PDF přílohy, které jsou bankovním výpisem K ÚČTU TÉTO FIRMY, a
 * naimportuje je stejnou cestou jako ruční „Nahrát PDF" — tedy včetně párování
 * plateb s fakturami a skládání denních výpisů do měsíčního.
 *
 * Běží jako NADSTAVBA skenu bankovních avíz nad tímtéž IMAP účtem (opt-in
 * `ingest_pdf_statements`): banky, které nenabízejí API, posílají výpis do schránky
 * jako přílohu — KB dokonce po každém pohybu zvlášť za každou měnu účtu.
 *
 * Pořadí vůči {@see EmailPdfInvoiceIngestor} je dané: výpis se posuzuje PRVNÍ.
 * Bankovní výpis nese jméno a adresu naší firmy, takže by ho rozpoznávač dokladů
 * mohl poslat do fronty přijatých faktur. Zápis do auditního logu příloh (unikátní
 * na dvojici firma + SHA) zároveň druhému ingestoru řekne, že příloha je vyřízená.
 *
 * Rozhodovací pořadí u jedné přílohy:
 *   1. není PDF / je větší než {@see MAX_ATTACHMENT_BYTES} → NEŘEŠÍME (ani nelogujeme;
 *      posouzení patří ingestoru faktur, ať se mu nesebere stopa v logu),
 *   2. textovou vrstvu nerozpozná žádný bankovní parser → NEŘEŠÍME (detto),
 *   3. výpis rozpoznán, ale číslo účtu neodpovídá žádnému účtu firmy →
 *      `skipped_not_statement` (cizí výpis se nikdy nesmí naimportovat pod naši firmu),
 *   4. parsování nebo import selže → `failed` se zprávou (ticho tady znamená
 *      ztracený výpis — parser má vlastní self-check proti hlavičkovým součtům),
 *   5. jinak `imported_statement` + vazba na založený výpis.
 *
 * Stav `skipped_not_statement` se při dalším skenu posuzuje ZNOVU: účet, který v době
 * prvního skenu nebyl v nastavení firmy, tam může přibýt a výpis se má doimportovat.
 */
final class EmailPdfStatementIngestor
{
    /** Strop odpovídá ručnímu uploadu PDF výpisu (BankStatementAction::importPdf). */
    public const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    /** Kolik příloh z jedné zprávy nejvýš posuzujeme (anti-DoS na zip-bomb maily). */
    private const MAX_ATTACHMENTS_PER_MESSAGE = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly BankEmailAttachmentIngestRepository $log,
        private readonly BankStatementPdfParserRegistry $parsers,
        private readonly StatementImporter $importer,
        /**
         * Výpis kreditní karty z e-mailu jde importem kreditních karet, ale jen k úvěrovému
         * účtu, který firma UŽ eviduje (pravidlo 3 výš: cizí výpis se nesmí naimportovat
         * pod naši firmu, a nový účet by si e-mail založil sám).
         */
        private readonly ?\MyInvoice\Service\Bank\CreditCard\CreditCardStatementImportService $creditCards = null,
        private readonly ?\MyInvoice\Repository\CreditCardAccountRepository $creditCardAccounts = null,
    ) {}

    /**
     * @param array<string,mixed> $settings IMAP účet
     * @return array{enabled:bool,considered:int,imported:int,skipped:int,rejected:int,failed:int,details:list<array<string,mixed>>}
     */
    public function ingestFromMessage(int $supplierId, array $settings, BankEmailNoticeMessage $message, ?EmailDistributionScope $scope = null): array
    {
        $summary = [
            'enabled' => !empty($settings['ingest_pdf_statements']),
            'considered' => 0,
            'imported' => 0,
            'skipped' => 0,
            'rejected' => 0,
            'failed' => 0,
            'details' => [],
        ];
        if (!$summary['enabled'] || $message->attachments === []) {
            return $summary;
        }

        $imapAccountId = isset($settings['id']) ? (int) $settings['id'] : null;
        $seen = 0;
        foreach ($message->attachments as $attachment) {
            if (!$attachment instanceof EmailAttachment) {
                continue;
            }
            if (++$seen > self::MAX_ATTACHMENTS_PER_MESSAGE) {
                break;
            }
            $detail = $this->ingestAttachment($supplierId, $imapAccountId, $message, $attachment, $scope);
            if ($detail === null) {
                continue; // není bankovní výpis — přílohu posoudí ingestor faktur
            }
            $summary['considered']++;
            $summary['details'][] = $detail;
            $bucket = match ((string) $detail['status']) {
                'imported_statement' => 'imported',
                'skipped_duplicate', 'skipped_not_statement' => 'skipped',
                'rejected' => 'rejected',
                default => 'failed',
            };
            $summary[$bucket]++;
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>|null NULL = příloha není bankovní výpis (neřešíme ji)
     */
    private function ingestAttachment(
        int $supplierId,
        ?int $imapAccountId,
        BankEmailNoticeMessage $message,
        EmailAttachment $attachment,
        ?EmailDistributionScope $scope = null,
    ): ?array {
        if (!$attachment->isPdf() || $attachment->size() > self::MAX_ATTACHMENT_BYTES) {
            return null;
        }
        // Firmy, jejichž účtům smí výpis patřit. Bez rozdělování jen firma schránky.
        $supplierIds = $scope?->bankSupplierIds ?? [$supplierId];

        $base = [
            'supplier_id' => $supplierId,
            'imap_account_id' => $imapAccountId,
            'message_id' => $message->messageId,
            'sender' => $message->sender,
            'subject' => $message->subject,
            'filename' => $attachment->filename,
            'sha256' => $attachment->sha256(),
            'size_bytes' => $attachment->size(),
        ];

        $known = $this->log->findBySha($supplierId, $base['sha256']);
        // Vazba na podání, které z přílohy vzniklo dřív, přežije přepsání řádku:
        // uživatel musí mít kam dojít pro doklad, který mezitím leží ve frontě
        // příchozích dokladů jako nepovedená AI extrakce toho samého výpisu.
        if ($known !== null && ($known['submission_id'] ?? null) !== null) {
            $base['submission_id'] = (int) $known['submission_id'];
        }
        $knownStatus = $known !== null ? (string) $known['status'] : null;
        // Hotová rozhodnutí se neopakují. Zamítnutí ANO: „výpis nepatří k žádnému účtu
        // firmy" zmizí, jakmile účet v nastavení přibude, a „není faktura" mohlo vzniknout
        // dřív, než uživatel načítání výpisů vůbec zapnul.
        if ($knownStatus === 'imported_statement') {
            return $this->record(
                $base,
                'skipped_duplicate',
                'Tenhle výpis už z e-mailu naimportovaný je.',
                statementId: isset($known['bank_statement_id']) ? (int) $known['bank_statement_id'] : null,
                persist: false,
            );
        }
        // Přílohu si vzala fronta příchozích dokladů. Rozhodnutí se ale mohlo
        // narodit dřív, než tahle cesta existovala: bankovní výpis nese jméno
        // i adresu naší firmy, takže ho rozpoznávač dokladů poslal do fronty
        // jako doklad a AI extrakce nad ním skončila na „chybí items". Dokud
        // ten řádek v logu držel, výpis už se z e-mailu nedal načíst NIKDY -
        // znovu poslaná tatáž příloha má tentýž SHA. Proto se posuzuje znovu,
        // ale jen dokud o něm nerozhodla tahle cesta (`bank_statement_id`).
        $takenByInvoiceQueue = ($knownStatus === 'imported' || $knownStatus === 'skipped_duplicate')
            && ($known['bank_statement_id'] ?? null) === null;
        if (($knownStatus === 'imported' || $knownStatus === 'skipped_duplicate') && !$takenByInvoiceQueue) {
            return null;
        }

        try {
            $text = $this->parsers->extractText($attachment->content);
        } catch (\Throwable) {
            return null; // bez textové vrstvy to výpis poznat nejde
        }
        if ($this->parsers->parserFor($text) === null) {
            return null;
        }

        try {
            $parsed = $this->parsers->parse($attachment->content);
        } catch (\Throwable $e) {
            // ⚠️ Záznam fronty dokladů se přepisuje JEN když z přílohy opravdu
            // vznikne výpis. Jinak by se tím zahodila vazba na založené podání
            // a uživatel by v logu příloh přestal vidět, kam se doklad poděl.
            return $takenByInvoiceQueue
                ? null
                : $this->record($base, 'failed', 'Výpis se nepodařilo zpracovat: ' . $e->getMessage());
        }

        $accountNumber = (string) ($parsed['header']['account_number'] ?? '');
        if (\MyInvoice\Service\Bank\CreditCard\CreditCardStatementImportService::isCreditCardStatement($parsed)) {
            return $this->ingestCreditCard($supplierIds, $base, $parsed, $attachment->content, $this->safeFilename($attachment->filename), $takenByInvoiceQueue);
        }
        $account = $this->resolveCurrencyAccount($supplierIds, $accountNumber, $parsed);
        if ($account === null) {
            return $takenByInvoiceQueue ? null : $this->record($base, 'skipped_not_statement', sprintf(
                count($supplierIds) > 1
                    ? 'Výpis k účtu %s: tenhle účet nepatří žádné z firem, do kterých schránka rozděluje, nebo mu odpovídá víc účtů. Doplňte ho v nastavení firmy a spusťte sken znovu.'
                    : 'Výpis k účtu %s: tenhle účet není mezi bankovními účty firmy, nebo mu odpovídá víc účtů. Doplňte ho v nastavení a spusťte sken znovu.',
                $accountNumber !== '' ? $accountNumber : 'neuveden',
            ));
        }
        $currencyId = $account['currency_id'];
        $base['target_supplier_id'] = $account['supplier_id'];

        try {
            $result = $this->importer->importParsedPdf(
                $parsed,
                $attachment->content,
                $this->safeFilename($attachment->filename),
                null,
                $currencyId,
            );
        } catch (\Throwable $e) {
            return $takenByInvoiceQueue
                ? null
                : $this->record($base, 'failed', 'Import výpisu selhal: ' . $e->getMessage());
        }

        // U denního výpisu je `statement_id` měsíční výpis, do kterého se složil —
        // do logu patří doklad, který z přílohy vznikl, tedy evidenční výpis.
        $statementId = (int) ($result['evidence_statement_id'] ?? $result['statement_id'] ?? 0);
        if (!empty($result['duplicate'])) {
            return $this->record(
                $base,
                'skipped_duplicate',
                'Tenhle výpis už v systému je.',
                statementId: $statementId > 0 ? $statementId : null,
            );
        }

        return $this->record(
            $base,
            'imported_statement',
            sprintf(
                'Naimportován bankovní výpis k účtu %s: %d pohybů, spárováno %d.',
                $accountNumber,
                (int) ($result['transactions'] ?? 0),
                (int) ($result['matched'] ?? 0),
            ) . (!empty($result['processing_failed'])
                ? ' Párování pohybů se nepodařilo dokončit, u výpisu použijte „Přepárovat výpis".'
                : ''),
            statementId: $statementId > 0 ? $statementId : null,
            matchedBy: isset($parsed['parser']) ? (string) $parsed['parser'] : null,
        );
    }

    /**
     * Měnový účet, kterému výpis patří (`currencies.id`), a jeho firma. NULL = žádný
     * nebo víc než jeden; obojí je důvod výpis NEimportovat. Tohle je hranice
     * tenantů: bez ní by stačilo poslat do schránky cizí výpis a stal by se dokladem
     * naší firmy. Při rozdělování se hledá mezi účty všech povolených firem a
     * jednoznačnost platí přes všechny dohromady.
     *
     * @param list<int> $supplierIds
     * @param array{header:array<string,mixed>,transactions:list<array<string,mixed>>} $parsed
     * @return array{currency_id:int,supplier_id:int}|null
     */
    private function resolveCurrencyAccount(array $supplierIds, string $accountNumber, array $parsed): ?array
    {
        if ($accountNumber === '' || $supplierIds === []) {
            return null;
        }
        $currency = $parsed['header']['account_currency'] ?? ($parsed['transactions'][0]['currency'] ?? null);
        $currency = is_string($currency) && $currency !== '' ? strtoupper($currency) : null;

        $in = implode(',', array_fill(0, count($supplierIds), '?'));
        $query = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, code, account_number, iban FROM currencies
              WHERE supplier_id IN ($in) AND is_active = 1 AND (account_number IS NOT NULL OR iban IS NOT NULL)"
        );
        $query->execute($supplierIds);
        $matches = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!AccountNumberNormalizer::matchesAny($accountNumber, $row['account_number'] ?? null, $row['iban'] ?? null)) {
                continue;
            }
            // Víceměnový účet se sdíleným číslem (RB: CZK/EUR/USD = jedno číslo):
            // rozhoduje měna výpisu, jinak by se EUR výpis naimportoval pod CZK účet.
            if ($currency !== null && strtoupper((string) $row['code']) !== $currency) {
                continue;
            }
            $matches[] = ['currency_id' => (int) $row['id'], 'supplier_id' => (int) $row['supplier_id']];
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private function record(
        array $base,
        string $status,
        string $reason,
        ?int $statementId = null,
        ?string $matchedBy = null,
        bool $persist = true,
    ): array {
        $row = $base + [
            'status' => $status,
            'reason' => $reason,
            'bank_statement_id' => $statementId,
            'matched_by' => $matchedBy,
        ];
        if ($persist) {
            // Zápis logu nesmí shodit sken avíz — auditní stopa je nadstavba.
            try {
                $this->log->record($row);
            } catch (\Throwable) {
            }
        }
        return $row;
    }

    /** Název pro doklad výpisu; přílohu bez `.pdf` přejmenujeme (magic check už prošel). */
    /**
     * Výpis kreditní karty: import jen k úvěrovému účtu, který firma už eviduje.
     *
     * @param list<int> $supplierIds
     * @param array<string,mixed> $base
     * @param array{header:array<string,mixed>, transactions:list<array<string,mixed>>} $parsed
     * @return array<string,mixed>|null
     */
    private function ingestCreditCard(array $supplierIds, array $base, array $parsed, string $content, string $filename, bool $takenByInvoiceQueue): ?array
    {
        $accountNumber = (string) ($parsed['header']['account_number'] ?? '');
        $bankCode = isset($parsed['header']['bank_code']) ? (string) $parsed['header']['bank_code'] : null;
        $owners = [];
        foreach ($supplierIds as $candidate) {
            $row = $this->creditCardAccounts?->findRegistryRow($candidate, $accountNumber, $bankCode);
            if ($row !== null && (string) $row['kind'] === 'credit_card') {
                $owners[] = $candidate;
            }
        }
        $supplierId = count($owners) === 1 ? $owners[0] : null;
        if ($this->creditCards === null || $supplierId === null) {
            return $takenByInvoiceQueue ? null : $this->record($base, 'skipped_not_statement', sprintf(
                'Výpis kreditní karty k účtu %s: tenhle úvěrový účet firma neeviduje. První výpis načtěte ručně v sekci Kreditní karty.',
                $accountNumber !== '' ? $accountNumber : 'neuveden',
            ));
        }
        $base['target_supplier_id'] = $supplierId;
        try {
            $result = $this->creditCards->importParsed($supplierId, $parsed, $content, $filename, null);
        } catch (\Throwable $e) {
            return $takenByInvoiceQueue ? null : $this->record($base, 'failed', 'Import výpisu kreditní karty selhal: ' . $e->getMessage());
        }
        $statementId = (int) ($result['statement_id'] ?? 0);
        if (!empty($result['duplicate'])) {
            return $this->record($base, 'skipped_duplicate', 'Tenhle výpis už v systému je.', statementId: $statementId > 0 ? $statementId : null);
        }
        return $this->record(
            $base,
            'imported_statement',
            sprintf('Naimportován výpis kreditní karty k účtu %s: %d pohybů.', $accountNumber, (int) ($result['transactions'] ?? 0)),
            statementId: $statementId > 0 ? $statementId : null,
            matchedBy: isset($parsed['parser']) ? (string) $parsed['parser'] : null,
        );
    }

    private function safeFilename(string $filename): string
    {
        $name = trim(basename(str_replace('\\', '/', $filename)));
        $name = (string) preg_replace('/[\x00-\x1F]/', '', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'vypis.pdf';
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
            $name .= '.pdf';
        }
        return mb_substr($name, 0, 200);
    }
}
