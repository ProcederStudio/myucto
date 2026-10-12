<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Převzaté odpisy dlouhodobého majetku: řádky odpisů převedených let a počáteční stavy
 * účetních odpisů z měsíčního plánu zdroje.
 *
 * Karta je v MyÚčtu vedená, jako by v něm vznikla: účetní odpis převedeného roku je řádek
 * zaúčtovaný převzatým deníkem ({@see DepreciationEntryRepository::MIGRATED_JOURNAL},
 * hromadné účtování odpisů ho znovu neúčtuje), daňový odpis je potvrzený řádek s původem
 * převodu v `detail` ({@see TAX_SOURCE}).
 *
 * Opakovaný převod přepíše jen řádek, který zapsal převod. Řádek zaúčtovaný nebo
 * potvrzený v MyÚčtu (ruční přerušení odpisu, oprava účetní) zůstává, stejně jako každý
 * řádek roku, jehož účetní období je uzavřené nebo zamčené - tam už se nic nemění.
 * Nezapsaný rozdíl vrací {@see confirm()} v `kept`, převod ho ohlásí.
 */
final class MigratedDepreciation
{
    /** Přepsat existující řádek roku: vždy (když se liší). */
    public const OVERWRITE_ALWAYS = 'always';

    /**
     * Přepsat jen řádek, který zaúčtoval převzatý deník (účetní řádek s MIGRATED_JOURNAL);
     * řádek zaúčtovaný nebo potvrzený v MyÚčtu zůstává.
     */
    public const OVERWRITE_OWN = 'own';

    /** Klíč původu daňového řádku v `detail` (hodnota {@see DepreciationEntryRepository::MIGRATED_JOURNAL}). */
    public const TAX_SOURCE = DepreciationEntryRepository::MIGRATED_TAX_SOURCE;

    public const KEPT_CLOSED = 'closed_period';
    public const KEPT_NOT_MIGRATED = 'not_migrated';

    public function __construct(
        private readonly DepreciationEntryRepository $entries,
        private readonly Connection $db,
    ) {}

    /** Řádek odpisu zapsal převod (účetní: zaúčtoval převzatý deník, daňový: původ v `detail`). */
    public static function isMigratedRow(?array $entry): bool
    {
        if ($entry === null) {
            return false;
        }
        if (($entry['kind'] ?? 'accounting') === 'accounting') {
            return DepreciationEntryRepository::isBookedByMigratedJournal($entry);
        }
        return DepreciationEntryRepository::isConfirmedByMigration($entry);
    }

    /**
     * Zapíše odpis roku, pokud řádek roku chybí, nebo se liší a smí se přepsat.
     *
     * @param self::OVERWRITE_* $overwrite účetní řádek: ALWAYS = přepsat vždy, OWN = jen řádek
     *        převzatého deníku; daňový řádek se přepíše jen převzatý ({@see isMigratedRow()})
     * @param bool $compareResidual shodný řádek = shodná částka I zůstatková cena (false = jen částka)
     * @param bool $clampResidual zapsat zápornou zůstatkovou cenu jako 0 (porovnává se nezkrácená, Money S3)
     * @param string $program název zdroje do detailu řádku („Money S3", „PREMIER")
     * @return array{written:bool,previous:?array<string,mixed>,kept:?string} previous = přepsaný
     *         řádek; kept = proč se odlišný řádek nezapsal (KEPT_*), null = zapsán nebo shodný
     */
    public function confirm(
        int $supplierId,
        int $assetId,
        string $kind,
        int $year,
        float $amount,
        float $full,
        float $residual,
        bool $paused,
        bool $half,
        ?int $months,
        string $program,
        string $status,
        string $overwrite,
        bool $compareResidual,
        bool $clampResidual = false,
    ): array {
        $existing = $this->entries->findYear($assetId, $kind, $year);
        if ($existing !== null) {
            $own = $kind === 'accounting'
                ? $overwrite === self::OVERWRITE_ALWAYS || DepreciationEntryRepository::isBookedByMigratedJournal($existing)
                : self::isMigratedRow($existing + ['kind' => $kind]);
            $same = ReconciliationTolerance::sameCent((float) $existing['amount'], $amount)
                && (!$compareResidual || ReconciliationTolerance::sameCent((float) $existing['residual_value_end'], $residual));
            if ($same) {
                return ['written' => false, 'previous' => null, 'kept' => null];
            }
            if (!$own) {
                return ['written' => false, 'previous' => null, 'kept' => self::KEPT_NOT_MIGRATED];
            }
        }
        if ($this->closed($supplierId, $year)) {
            return ['written' => false, 'previous' => null, 'kept' => self::KEPT_CLOSED];
        }
        $this->entries->upsert([
            'supplier_id' => $supplierId,
            'asset_id' => $assetId,
            'kind' => $kind,
            'fiscal_year' => $year,
            'amount' => $amount,
            'full_amount' => $full,
            'residual_value_end' => $clampResidual ? max(0.0, $residual) : $residual,
            'is_paused' => $paused,
            'is_half' => $half,
            'months_count' => $months,
            'detail' => json_encode($kind === 'accounting'
                ? ['journal' => DepreciationEntryRepository::MIGRATED_JOURNAL, 'program' => $program]
                : [self::TAX_SOURCE => DepreciationEntryRepository::MIGRATED_JOURNAL, 'program' => $program]),
            'status' => $status,
        ]);
        return ['written' => true, 'previous' => $existing, 'kept' => null];
    }

    /** Upozornění, že se odlišný odpis zdroje nezapsal ({@see confirm()} `kept`). */
    public static function reportKept(ImportProtocol $protocol, string $step, string $number, string $kind, int $year, float $amount, string $kept, string $program): void
    {
        $protocol->warn($step, 'depreciation_kept', sprintf(
            'Majetek %s: %s odpis %d podle %s (%s Kč) se nezapsal, %s.',
            $number, $kind === 'tax' ? 'daňový' : 'účetní', $year, $program, number_format($amount, 2, ',', ' '),
            $kept === self::KEPT_CLOSED
                ? 'účetní období roku je uzavřené nebo zamčené'
                : 'řádek roku zapsalo nebo upravilo MyÚčto (ne převod) a zůstává; zkontrolujte ho ručně'
        ), ['document_no' => $number, 'year' => $year, 'kind' => $kind, 'reason' => $kept]);
    }

    /**
     * Účetní období roku je uzavřené, nebo je celé zamčené (`locked_until` dosahuje jeho
     * posledního dne). Konec roku = konec účetního období firmy: existující období, jinak
     * podle kalendáře účetního roku firmy (u hospodářského roku např. 31. 7. následujícího
     * roku, u kalendářního 31. 12.).
     */
    private function closed(int $supplierId, int $year): bool
    {
        $pdo = $this->db->pdo();
        $period = $pdo->prepare('SELECT status, ends_on FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?');
        $period->execute([$supplierId, $year]);
        $row = $period->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false && (string) $row['status'] !== 'open') {
            return true;
        }
        $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        if (!is_string($lockedUntil) || $lockedUntil === '') {
            return false;
        }
        $periodEnd = $row !== false
            ? substr((string) $row['ends_on'], 0, 10)
            : (new AccountingPeriodRepository($this->db))->fiscalCalendar($supplierId)->periodEnd($year);
        return $lockedUntil >= $periodEnd;
    }

    /** Počet měsíců od `$from` (bez něj) do `$to` včetně, oba `Y-m` (nebo datum). */
    public static function monthsBetween(string $from, string $to): int
    {
        return ((int) substr($to, 0, 4) - (int) substr($from, 0, 4)) * 12 + (int) substr($to, 5, 2) - (int) substr($from, 5, 2);
    }

    /**
     * Počáteční stavy účetních odpisů z měsíčního plánu zdroje: měsíce od zařazení do
     * posledního zaúčtovaného (zdroj odpisuje od měsíce zařazení, MyÚčto od následujícího,
     * počítá se proto kalendářně od zařazení), jejich součet podle plánu a doba odpisování.
     *
     * @param array<string,float> $monthPlan `Y-m` → odpis, seřazené
     * @param string|null $bookedThrough poslední zaúčtovaný měsíc `Y-m`; null = žádný
     * @return array{0:int,1:float,2:?int} měsíce, částka (zaokrouhlená), doba odpisování v měsících (null bez plánu)
     */
    public static function accountingOpening(string $inUse, array $monthPlan, ?string $bookedThrough): array
    {
        if ($monthPlan === []) {
            return [0, 0.0, null];
        }
        $start = substr($inUse, 0, 7);
        $last = (string) array_key_last($monthPlan);
        $cutoff = $bookedThrough !== null ? min($bookedThrough, $last) : null;
        $amount = 0.0;
        if ($cutoff !== null) {
            foreach ($monthPlan as $month => $value) {
                if ($month <= $cutoff) {
                    $amount += $value;
                }
            }
        }
        return [
            $cutoff !== null ? max(0, self::monthsBetween($start, $cutoff)) : 0,
            round($amount, 2),
            max(1, self::monthsBetween($start, $last)),
        ];
    }
}
