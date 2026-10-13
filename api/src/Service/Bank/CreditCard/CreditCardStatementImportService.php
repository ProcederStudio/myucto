<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\CreditCard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Service\Accounting\CreditCard\CreditCardAccounts;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry;
use MyInvoice\Service\Bank\StatementImporter;
use PDO;

/**
 * Import výpisu z úvěrového účtu kreditní karty - jediná cesta, kterou kreditní výpis
 * vstupuje do aplikace (stránka Kreditní karty i Banka → Nahrát PDF).
 *
 * Výpis se ukládá stejně jako výpis běžného účtu ({@see StatementImporter}: deduplikace
 * souboru i pohybů, uložené PDF, párování, automatické zaúčtování). Navíc:
 *
 *  1. úvěrový účet se zaeviduje firmě, která výpis nahrála (explicitně, ne podle čísla
 *     účtu), a dostane analytiku 231 dřív, než se začne účtovat;
 *  2. účet, který jiná firma eviduje, se odmítne (vlastnictví se nesmí odvozovat z čísla);
 *  3. účet, který firma vede jako BĚŽNÝ a má k němu výpisy, se sám nepřepne - historie
 *     by zůstala na 221. Import vrátí `account_is_bank_account` a převod udělá
 *     {@see \MyInvoice\Service\Accounting\CreditCard\CreditCardConversionService} na pokyn uživatele;
 *  4. výpis se připíše firmě (`bank_statements.supplier_id`).
 *
 * Kreditní účet v cizí měně se odmítá: přecenění 231 k rozvahovému dni aplikace nedělá
 * a všechny čtyři podporované banky vedou kreditní účty podnikatelů v CZK.
 */
final class CreditCardStatementImportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly BankStatementPdfParserRegistry $parsers,
        private readonly StatementImporter $importer,
        private readonly CreditCardAccountRepository $accounts,
        private readonly CreditCardAccounts $analytics,
        private readonly BankStatementOwnershipResolver $ownership,
    ) {}

    public static function isCreditCardStatement(array $parsed): bool
    {
        return ($parsed['header']['account_kind'] ?? null) === 'credit_card';
    }

    /**
     * Firma, které výpis patří: aktivní, pokud úvěrový účet eviduje nebo ho neeviduje
     * nikdo; jinak ta JEDINÁ z dalších firem uživatele, která ho eviduje. Nový úvěrový
     * účet se tak dál zakládá v aktivní firmě a existující se najde i odjinud.
     *
     * @param array{header:array<string,mixed>} $parsed
     * @param list<int> $otherSupplierIds firmy, kam smí uživatel importovat banku
     */
    public function ownerAmong(array $parsed, int $currentSupplierId, array $otherSupplierIds): int
    {
        $accountNumber = trim((string) ($parsed['header']['account_number'] ?? ''));
        $bankCode = isset($parsed['header']['bank_code']) && (string) $parsed['header']['bank_code'] !== ''
            ? (string) $parsed['header']['bank_code'] : null;
        if ($accountNumber === '' || $otherSupplierIds === []) {
            return $currentSupplierId;
        }
        $owns = function (int $supplierId) use ($accountNumber, $bankCode): bool {
            $row = $this->accounts->findRegistryRow($supplierId, $accountNumber, $bankCode);
            return $row !== null && (string) $row['kind'] === 'credit_card';
        };
        if ($owns($currentSupplierId)) {
            return $currentSupplierId;
        }
        $owners = array_values(array_filter(
            array_unique($otherSupplierIds),
            static fn (int $id): bool => $id !== $currentSupplierId,
        ));
        $owners = array_values(array_filter($owners, $owns));
        return count($owners) === 1 ? $owners[0] : $currentSupplierId;
    }

    /**
     * @param list<int> $otherSupplierIds další firmy, kam smí výpis odejít (viz {@see ownerAmong()})
     * @return array<string,mixed> výsledek importu + credit_card_account_id + supplier_id
     */
    public function importPdf(int $supplierId, string $pdfBytes, string $fileName, ?int $userId, ?int $expectedAccountId = null, array $otherSupplierIds = []): array
    {
        try {
            $parsed = $this->parsers->parse($pdfBytes);
        } catch (\RuntimeException $e) {
            throw new PostingException('parse_failed', 'Nelze načíst výpis: ' . $e->getMessage(), 400);
        }
        if (!self::isCreditCardStatement($parsed)) {
            throw new PostingException(
                'not_credit_card_statement',
                'Soubor není výpis kreditní karty. Výpis běžného účtu nahrajte v sekci Banka.',
                422,
            );
        }
        $owner = $this->ownerAmong($parsed, $supplierId, $otherSupplierIds);
        // Očekávaný účet zvolila obrazovka aktivní firmy, v jiné firmě nic neznamená.
        return $this->importParsed($owner, $parsed, $pdfBytes, $fileName, $userId, $owner === $supplierId ? $expectedAccountId : null)
            + ['supplier_id' => $owner];
    }

    /**
     * @param array{header:array<string,mixed>, transactions:list<array<string,mixed>>} $parsed
     * @return array<string,mixed>
     */
    public function importParsed(int $supplierId, array $parsed, string $pdfBytes, string $fileName, ?int $userId, ?int $expectedAccountId = null): array
    {
        // Úvěrový účet (231) je pojem podvojného účetnictví. V daňové evidenci by výpis
        // kreditky vstoupil do peněžního deníku jako peníze: dluh by snižoval zůstatek
        // a splátky by se tvářily jako příjem (resp. výdaj dvakrát).
        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ((string) $mode->fetchColumn() !== 'double_entry') {
            throw new PostingException(
                'credit_card_requires_double_entry',
                'Výpisy kreditní karty jde načíst jen u firmy v podvojném účetnictví.',
                422,
            );
        }
        $h = $parsed['header'];
        $currency = strtoupper((string) ($h['currency'] ?? 'CZK'));
        if ($currency !== 'CZK') {
            throw new PostingException(
                'unsupported_currency',
                'Kreditní účet v měně ' . $currency . ' zatím nejde načíst - podporované jsou úvěrové účty v Kč.',
                422,
            );
        }
        $accountNumber = trim((string) ($h['account_number'] ?? ''));
        $bankCode = isset($h['bank_code']) && (string) $h['bank_code'] !== '' ? (string) $h['bank_code'] : null;
        if (AccountNumberNormalizer::canonical($accountNumber) === null) {
            throw new PostingException('parse_failed', 'Ve výpisu chybí číslo úvěrového účtu.', 400);
        }

        $duplicate = $this->duplicateStatement($supplierId, $pdfBytes);
        if ($duplicate !== null) {
            return $duplicate;
        }

        $this->assertNotForeign($supplierId, $accountNumber, $bankCode);
        $account = $this->ensureAccount($supplierId, $h, $userId);
        if ($expectedAccountId !== null && $expectedAccountId !== (int) $account['id']) {
            throw new PostingException(
                'account_mismatch',
                'Výpis patří k jinému úvěrovému účtu (' . $accountNumber . ').',
                422,
                ['credit_card_account_id' => (int) $account['id']],
            );
        }
        $this->accounts->fillFromStatement($supplierId, (int) $account['id'], [
            'credit_limit'        => $h['credit_limit'] ?? null,
            'repayment_account'   => $h['repayment_account'] ?? null,
            'repayment_bank_code' => $h['repayment_bank_code'] ?? null,
            'repayment_vs'        => $h['repayment_vs'] ?? null,
        ]);
        // Analytika 231 musí existovat dřív, než import začne pohyby účtovat (náhledy ji
        // pak ukazují přesně). Firma bez podvojného účetnictví 231 v osnově nemá - nic se neděje.
        $this->analytics->ensureAnalytic($supplierId, $this->accounts->find($supplierId, (int) $account['id']) ?? $account);

        $result = $this->importer->importParsedPdf($parsed, $pdfBytes, $fileName, $userId, null, $supplierId);
        $statementId = (int) $result['statement_id'];
        if (!$this->ownership->statementOwned($statementId, $supplierId)) {
            throw new PostingException('statement_not_owned', 'Výpis nejde přiřadit této firmě.', 409);
        }
        return $result + ['credit_card_account_id' => (int) $account['id']];
    }

    /**
     * Týž soubor už je naimportovaný u vlastní firmy: vrátí původní výpis.
     *
     * @return array<string,mixed>|null
     */
    private function duplicateStatement(int $supplierId, string $pdfBytes): ?array
    {
        $hash = hash('sha256', $pdfBytes);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM bank_statements WHERE supplier_id = ? AND (file_hash = ? OR pdf_hash = ?) ORDER BY id LIMIT 1');
        $stmt->execute([$supplierId, $hash, $hash]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        $row = $this->db->pdo()->prepare('SELECT account_number, bank_code FROM bank_statements WHERE id = ?');
        $row->execute([(int) $id]);
        $s = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $registry = $this->accounts->findRegistryRow($supplierId, (string) ($s['account_number'] ?? ''), $s['bank_code'] ?? null);
        $account = $registry !== null ? $this->accounts->findByBankAccount($supplierId, (int) $registry['id']) : null;
        return [
            'statement_id'           => (int) $id,
            'transactions'           => 0,
            'matched'                => 0,
            'duplicate'              => true,
            'credit_card_account_id' => $account !== null ? (int) $account['id'] : null,
        ];
    }

    private function assertNotForeign(int $supplierId, string $accountNumber, ?string $bankCode): void
    {
        $canonical = AccountNumberNormalizer::canonical($accountNumber);
        $bank = AccountNumberNormalizer::canonicalBankCode($bankCode) ?? '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM supplier_bank_accounts
              WHERE supplier_id <> ? AND is_active = 1 AND account_canonical = ? AND bank_code_norm = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $canonical, $bank]);
        if ($stmt->fetchColumn() !== false
            || $this->ownership->accountClaimedByOtherSupplier($supplierId, $accountNumber)) {
            throw new PostingException('account_foreign', 'Úvěrový účet ' . $accountNumber . ' eviduje jiná firma.', 409);
        }
    }

    /**
     * Úvěrový účet firmy pro výpis - existující, nebo nově zaevidovaný (neověřený).
     *
     * @param array<string,mixed> $h hlavička výpisu
     * @return array<string,mixed>
     */
    private function ensureAccount(int $supplierId, array $h, ?int $userId): array
    {
        $accountNumber = (string) $h['account_number'];
        $bankCode = isset($h['bank_code']) && (string) $h['bank_code'] !== '' ? (string) $h['bank_code'] : null;
        $registry = $this->accounts->findRegistryRow($supplierId, $accountNumber, $bankCode);
        if ($registry !== null) {
            if ((string) $registry['kind'] === 'credit_card') {
                $existing = $this->accounts->findByBankAccount($supplierId, (int) $registry['id']);
                if ($existing !== null) {
                    return $existing;
                }
            } elseif ($this->hasStatements($supplierId, $registry)) {
                throw new PostingException(
                    'account_is_bank_account',
                    'Účet ' . $accountNumber . ' firma vede jako bankovní účet a má k němu výpisy. '
                        . 'Převeďte ho na kreditní kartu - dosavadní zápisy se přeúčtují z 221 na 231.',
                    409,
                    ['bank_account_id' => (int) $registry['id']],
                );
            }
        }
        $issuer = (string) ($h['issuer'] ?? 'other');
        $id = $this->accounts->create($supplierId, [
            'issuer'              => $issuer,
            'label'               => self::defaultLabel($issuer, $accountNumber),
            'account_number'      => $accountNumber,
            'bank_code'           => $bankCode,
            'currency'            => 'CZK',
            'credit_limit'        => isset($h['credit_limit']) ? (float) $h['credit_limit'] : null,
            'repayment_account'   => $h['repayment_account'] ?? null,
            'repayment_bank_code' => $h['repayment_bank_code'] ?? null,
            'repayment_vs'        => $h['repayment_vs'] ?? null,
            'is_verified'         => false,
        ], $userId);
        return $this->accounts->find($supplierId, $id) ?? throw new \RuntimeException('Úvěrový účet se nepodařilo založit.');
    }

    /** @param array<string,mixed> $registry řádek supplier_bank_accounts */
    public function hasStatements(int $supplierId, array $registry): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM bank_statements bs
              WHERE bs.supplier_id = ?
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', '')) = ?
                AND COALESCE(bs.bank_code, '') = ?
              LIMIT 1"
        );
        $stmt->execute([$supplierId, (string) $registry['account_canonical'], (string) $registry['bank_code_norm']]);
        return $stmt->fetchColumn() !== false;
    }

    private static function defaultLabel(string $issuer, string $accountNumber): string
    {
        $bank = match ($issuer) {
            'kb'    => 'KB',
            'rb'    => 'Raiffeisenbank',
            'csob'  => 'ČSOB',
            'erste' => 'Česká spořitelna',
            default => 'Kreditní karta',
        };
        return $bank . ' ' . $accountNumber;
    }
}
