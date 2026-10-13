<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Epo\Isds\TaxIsdsRouting;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Platební údaje k vygenerovanému přiznání k DPH: komu, kolik, s jakým VS a do kdy.
 *
 * Všechno se čte z archivovaného XML snapshotu, ne z aktuálních dat firmy. Platit se má
 * přesně to, co se podalo: FÚ z `c_ufo_cil`/`c_ufo`, VS z DIČ ve větě P a částka
 * z rekapitulace ve větě 6 (ř. 64 vlastní daň, ř. 65 nadměrný odpočet, u dodatečného
 * ř. 66 rozdíl proti poslední známé dani).
 *
 * Splatnost řádného i opravného přiznání je shodná s lhůtou pro podání (§ 101 odst. 4
 * ZDPH). Dodatečně tvrzená daň je splatná ve lhůtě pro podání dodatečného přiznání
 * (§ 141 odst. 4 DŘ), tu ale určuje den zjištění důvodu, takže ji aplikace nedopočítává.
 */
final class VatPaymentInstruction
{
    /** Tolerance haléřového rozdílu mezi daní a úhradou. */
    private const PAID_TOLERANCE = 1.0;

    public function __construct(private readonly QrPaymentGenerator $qr) {}

    /**
     * Platební údaje včetně QR a zjištěné úhrady z bankovních výpisů firmy.
     *
     * @param array<string,mixed> $submission řádek `tax_submissions` včetně `xml_content`
     * @return array<string,mixed>
     */
    public function forSubmission(array $submission, ?\PDO $pdo = null): array
    {
        $out = self::describe($submission);
        if (!$out['supported']) {
            return $out;
        }
        $out['payment'] = $pdo !== null && isset($submission['supplier_id'])
            ? self::findPayment($pdo, (int) $submission['supplier_id'], $out, $submission)
            : null;

        $account = $out['account'];
        $out['qr_data_uri'] = null;
        if ($out['payable'] > 0 && $account !== null && $out['variable_symbol'] !== null) {
            $out['qr_data_uri'] = $this->qr->generate(
                'CZK',
                $out['payable'],
                $out['variable_symbol'],
                ['account_number' => $account['prefix'] . '-' . $account['number'], 'bank_code' => $account['bank_code'], 'iban' => '', 'bic' => ''],
                $account['office_name'],
                $out['due_date'] !== null ? new \DateTimeImmutable($out['due_date']) : null,
                'DPH ' . self::periodLabel($submission),
                $out['due_date'] !== null,
            );
        }
        return $out;
    }

    /**
     * Platební údaje bez QR (sdílí archiv EPO i dashboard).
     *
     * @param array<string,mixed> $submission
     * @return array<string,mixed>
     */
    public static function describe(array $submission): array
    {
        if (strtolower((string) ($submission['form_code'] ?? '')) !== 'dphdp3') {
            return ['supported' => false];
        }
        $data = self::parse((string) ($submission['xml_content'] ?? ''));
        return [
            'supported' => true,
            'amendment' => $data['amendment'],
            'payable' => $data['payable'],
            'refund' => $data['refund'],
            'due_date' => $data['amendment'] ? null : self::dueDate($submission),
            'variable_symbol' => $data['variable_symbol'],
            'account' => TaxOfficeBankAccounts::account($data['office_code'], TaxOfficeBankAccounts::PREFIX_VAT),
            'office_code' => $data['office_code'],
        ];
    }

    /**
     * Úhrada daně podle bankovních výpisů firmy: odchozí platby na účet DPH (předčíslí
     * 705) se stejným VS, nebo na matriku úřadu z přiznání, připsané po skončení období.
     * Uhrazeno = součet plateb pokryje daň. Platby za jiná období se stejným VS mohou
     * zkreslit výsledek jen tehdy, když by se hradily po konci tohoto období.
     *
     * @param array<string,mixed> $described výstup {@see describe()}
     * @param array<string,mixed> $submission
     * @return array{paid:bool,paid_amount:float,paid_at:?string}|null null = není co hradit
     */
    public static function findPayment(\PDO $pdo, int $supplierId, array $described, array $submission): ?array
    {
        $payable = (float) ($described['payable'] ?? 0);
        $periodEnd = self::periodEnd($submission);
        if ($payable <= 0 || $periodEnd === null) {
            return null;
        }
        $vs = $described['variable_symbol'] !== null
            ? VariableSymbolNormalizer::forMatching((string) $described['variable_symbol'])
            : '';
        $officeNumber = $described['account']['number'] ?? null;

        $stmt = $pdo->prepare(
            'SELECT bt.amount, bt.posted_at, bt.counterparty_account, bt.variable_symbol
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.supplier_id = ? AND bt.amount < 0 AND bt.posted_at > ?
              ORDER BY bt.posted_at'
        );
        $stmt->execute([$supplierId, $periodEnd]);

        $paid = 0.0;
        $paidAt = null;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $tx) {
            $counterparty = trim((string) ($tx['counterparty_account'] ?? ''));
            if ($counterparty === '' || AccountNumberNormalizer::czechAccountPrefix($counterparty) !== TaxOfficeBankAccounts::PREFIX_VAT) {
                continue;
            }
            $digits = preg_replace('/\D/', '', preg_replace('#/\d{4}$#', '', $counterparty) ?? '') ?? '';
            $sameOffice = $officeNumber !== null && str_ends_with($digits, $officeNumber);
            $sameVs = $vs !== '' && VariableSymbolNormalizer::forMatching((string) ($tx['variable_symbol'] ?? '')) === $vs;
            if (!$sameOffice && !$sameVs) {
                continue;
            }
            $paid += abs((float) $tx['amount']);
            $paidAt = substr((string) $tx['posted_at'], 0, 10);
            if ($paid + self::PAID_TOLERANCE >= $payable) {
                break;
            }
        }

        return [
            'paid' => $paid + self::PAID_TOLERANCE >= $payable,
            'paid_amount' => round($paid, 2),
            'paid_at' => $paidAt,
        ];
    }

    /**
     * @return array{office_code:?string,variable_symbol:?string,amendment:bool,payable:float,refund:float}
     */
    public static function parse(string $xml): array
    {
        $out = ['office_code' => null, 'variable_symbol' => null, 'amendment' => false, 'payable' => 0.0, 'refund' => 0.0];
        if ($xml === '' || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            return $out;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                return $out;
            }
            $xpath = new \DOMXPath($document);
            $attr = static function (string $element, string $name) use ($xpath): ?string {
                $nodes = $xpath->query('//*[local-name()="' . $element . '"]/@' . $name);
                $value = $nodes !== false && $nodes->length > 0 ? trim((string) $nodes->item(0)?->nodeValue) : '';
                return $value !== '' ? $value : null;
            };

            $out['office_code'] = TaxIsdsRouting::officeCodeFromXml($xml);
            $dic = preg_replace('/\D/', '', (string) $attr('VetaP', 'dic'));
            $out['variable_symbol'] = $dic !== '' ? $dic : null;
            $out['amendment'] = in_array($attr('VetaD', 'dapdph_forma'), ['D', 'E'], true);

            if ($out['amendment']) {
                $difference = (float) ($attr('Veta6', 'dano') ?? 0);
                $out['payable'] = max(0.0, $difference);
                $out['refund'] = max(0.0, -$difference);
            } else {
                $out['payable'] = (float) ($attr('Veta6', 'dano_da') ?? 0);
                $out['refund'] = (float) ($attr('Veta6', 'dano_no') ?? 0);
            }
            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** 25. den po skončení zdaňovacího období, posunutý na pracovní den. */
    public static function dueDate(array $submission): ?string
    {
        $lastMonth = self::lastMonth($submission);
        if ($lastMonth === null) {
            return null;
        }
        $year = (int) $submission['period_year'];
        return $lastMonth === 12
            ? CzechWorkingDays::deadline($year + 1, 1)
            : CzechWorkingDays::deadline($year, $lastMonth + 1);
    }

    private static function periodEnd(array $submission): ?string
    {
        $lastMonth = self::lastMonth($submission);
        if ($lastMonth === null) {
            return null;
        }
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', (int) $submission['period_year'], $lastMonth)))
            ->modify('last day of this month')
            ->format('Y-m-d');
    }

    private static function lastMonth(array $submission): ?int
    {
        $year = (int) ($submission['period_year'] ?? 0);
        $month = ($submission['period_month'] ?? null) !== null ? (int) $submission['period_month'] : null;
        $quarter = ($submission['period_quarter'] ?? null) !== null ? (int) $submission['period_quarter'] : null;
        $lastMonth = $month ?? ($quarter !== null ? $quarter * 3 : null);
        return $year > 0 && $lastMonth !== null && $lastMonth >= 1 && $lastMonth <= 12 ? $lastMonth : null;
    }

    private static function periodLabel(array $submission): string
    {
        $year = (int) ($submission['period_year'] ?? 0);
        if (($submission['period_quarter'] ?? null) !== null) {
            return $year . ' Q' . (int) $submission['period_quarter'];
        }
        return sprintf('%02d/%04d', (int) ($submission['period_month'] ?? 0), $year);
    }
}
