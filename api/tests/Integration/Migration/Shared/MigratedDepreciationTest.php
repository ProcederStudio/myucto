<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Migration\Shared\MigratedDepreciation;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MigratedDepreciationTest extends SharedMigrationDbTestCase
{
    public function testOwnPolicyKeepsAccountingRowBookedInMyUcto(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $migrated = new MigratedDepreciation($entries, $this->db);

        $first = $migrated->confirm($supplier, $asset, 'accounting', 2024, 1000.0, 1000.0, 9000.0, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, true);
        self::assertTrue($first['written']);
        $row = $entries->findYear($asset, 'accounting', 2024);
        self::assertTrue(DepreciationEntryRepository::isBookedByMigratedJournal($row));
        self::assertSame(['journal' => DepreciationEntryRepository::MIGRATED_JOURNAL, 'program' => 'Money S3'], is_string($row['detail']) ? json_decode($row['detail'], true) : $row['detail']);

        self::assertFalse($migrated->confirm($supplier, $asset, 'accounting', 2024, 1000.0, 1000.0, 9000.004, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, true)['written'], 'shodný řádek');
        $again = $migrated->confirm($supplier, $asset, 'accounting', 2024, 1100.0, 1100.0, 8900.0, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, true);
        self::assertTrue($again['written'], 'vlastní řádek převodu se srovná');
        self::assertSame(1000.0, (float) $again['previous']['amount']);

        $this->db->pdo()->prepare('UPDATE depreciation_entries SET detail = NULL WHERE asset_id = ?')->execute([$asset]);
        self::assertFalse($migrated->confirm($supplier, $asset, 'accounting', 2024, 1200.0, 1200.0, 8800.0, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, true)['written'], 'řádek zaúčtovaný v MyÚčtu se nepřepíše');
    }

    public function testTaxRowConfirmedInMyUctoIsKept(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $migrated = new MigratedDepreciation($entries, $this->db);
        // Potvrzené přerušení odpisu v MyÚčtu (řádek bez původu převodu).
        $entries->upsert(['supplier_id' => $supplier, 'asset_id' => $asset, 'kind' => 'tax', 'fiscal_year' => 2024, 'amount' => 0.0, 'full_amount' => 0.0,
            'residual_value_end' => 10000.0, 'is_paused' => true, 'is_half' => false, 'months_count' => null, 'detail' => null, 'status' => 'confirmed']);

        $result = $migrated->confirm($supplier, $asset, 'tax', 2024, 2000.0, 2000.0, 8000.0, false, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_ALWAYS, false);
        self::assertFalse($result['written']);
        self::assertSame(MigratedDepreciation::KEPT_NOT_MIGRATED, $result['kept']);
        $row = $entries->findYear($asset, 'tax', 2024);
        self::assertTrue((bool) $row['is_paused']);
        self::assertSame(0.0, (float) $row['amount']);
    }

    public function testMigratedTaxRowIsMarkedAndRealigned(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $migrated = new MigratedDepreciation($entries, $this->db);
        self::assertTrue($migrated->confirm($supplier, $asset, 'tax', 2024, 2000.0, 2000.0, 8000.0, false, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_ALWAYS, false)['written']);
        $row = $entries->findYear($asset, 'tax', 2024);
        self::assertTrue(MigratedDepreciation::isMigratedRow($row));
        self::assertFalse(DepreciationEntryRepository::isBookedByMigratedJournal($row), 'daňový řádek není zaúčtovaný deníkem');

        $again = $migrated->confirm($supplier, $asset, 'tax', 2024, 2500.0, 2500.0, 7500.0, false, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_ALWAYS, false);
        self::assertTrue($again['written'], 'vlastní řádek převodu se srovná se zdrojem');
        self::assertSame(2000.0, (float) $again['previous']['amount']);
    }

    public function testClosedPeriodIsNeverRewritten(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $migrated = new MigratedDepreciation($entries, $this->db);
        $migrated->confirm($supplier, $asset, 'tax', 2024, 2000.0, 2000.0, 8000.0, false, false, null, 'Money S3', 'confirmed', MigratedDepreciation::OVERWRITE_OWN, false);
        $migrated->confirm($supplier, $asset, 'accounting', 2024, 1000.0, 1000.0, 9000.0, false, false, 12, 'Money S3', 'posted', MigratedDepreciation::OVERWRITE_OWN, false);
        $period = $this->period($supplier, 2024);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$period]);

        foreach (['tax' => 2100.0, 'accounting' => 1100.0] as $kind => $amount) {
            $result = $migrated->confirm($supplier, $asset, $kind, 2024, $amount, $amount, 7000.0, false, false, 12, 'Money S3', 'confirmed', MigratedDepreciation::OVERWRITE_OWN, false);
            self::assertSame([false, MigratedDepreciation::KEPT_CLOSED], [$result['written'], $result['kept']], $kind);
        }
        self::assertSame(2000.0, (float) $entries->findYear($asset, 'tax', 2024)['amount']);
        self::assertSame(1000.0, (float) $entries->findYear($asset, 'accounting', 2024)['amount']);
        $same = $migrated->confirm($supplier, $asset, 'tax', 2024, 2000.0, 2000.0, 8000.0, false, false, null, 'Money S3', 'confirmed', MigratedDepreciation::OVERWRITE_OWN, false);
        self::assertSame([false, null], [$same['written'], $same['kept']], 'shodný řádek se nehlásí');
    }

    public function testLockOfFiscalYearCountsWithPeriodEndNotCalendarYearEnd(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$supplier]);
        $lock = $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)');
        $migrated = new MigratedDepreciation($entries, $this->db);

        // Hospodářský rok 2024 = 1. 8. 2024 – 31. 7. 2025; zámek k 31. 12. 2024 ho celý nezamyká.
        $lock->execute([$supplier, '2024-12-31']);
        $open = $migrated->confirm($supplier, $asset, 'tax', 2024, 2000.0, 2000.0, 8000.0, false, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_ALWAYS, false);
        self::assertSame([true, null], [$open['written'], $open['kept']], 'bez období: konec roku podle kalendáře firmy');

        $pdo->prepare('INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on) VALUES (?, 2024, ?, ?)')
            ->execute([$supplier, '2024-08-01', '2025-07-31']);
        $again = $migrated->confirm($supplier, $asset, 'tax', 2024, 2100.0, 2100.0, 7900.0, false, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_ALWAYS, false);
        self::assertSame([true, null], [$again['written'], $again['kept']], 'existující období: konec podle jeho hranic');

        $lock->execute([$supplier, '2025-07-31']);
        $closed = $migrated->confirm($supplier, $asset, 'tax', 2024, 2200.0, 2200.0, 7800.0, false, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_ALWAYS, false);
        self::assertSame([false, MigratedDepreciation::KEPT_CLOSED], [$closed['written'], $closed['kept']]);
        self::assertSame(2100.0, (float) $entries->findYear($asset, 'tax', 2024)['amount']);
    }

    public function testLockOfCalendarYearStillEndsOnDecember31(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)')
            ->execute([$supplier, '2024-12-31']);
        $result = (new MigratedDepreciation($entries, $this->db))
            ->confirm($supplier, $asset, 'tax', 2024, 2000.0, 2000.0, 8000.0, false, false, null, 'PREMIER', 'confirmed', MigratedDepreciation::OVERWRITE_ALWAYS, false);
        self::assertSame([false, MigratedDepreciation::KEPT_CLOSED], [$result['written'], $result['kept']]);
    }

    public function testClampWritesZeroButComparesRawResidual(): void
    {
        [$supplier, $asset, $entries] = $this->asset();
        $migrated = new MigratedDepreciation($entries, $this->db);
        $migrated->confirm($supplier, $asset, 'tax', 2024, 500.0, 500.0, -3.0, false, false, null, 'Money S3', 'confirmed', MigratedDepreciation::OVERWRITE_OWN, true, true);
        self::assertSame(0.0, (float) $entries->findYear($asset, 'tax', 2024)['residual_value_end']);
        self::assertTrue($migrated->confirm($supplier, $asset, 'tax', 2024, 500.0, 500.0, -3.0, false, false, null, 'Money S3', 'confirmed', MigratedDepreciation::OVERWRITE_OWN, true, true)['written'],
            'nezkrácená zůstatková cena se od zapsané liší, řádek se zapíše znovu (stejné hodnoty)');
    }

    /** @return array{0:int,1:int,2:DepreciationEntryRepository} */
    private function asset(): array
    {
        $supplier = $this->supplier();
        $this->db->pdo()->prepare("INSERT INTO assets (supplier_id, inventory_number, name, acquisition_date, input_price) VALUES (?, 'M-1', 'Stroj', '2020-01-01', 10000)")
            ->execute([$supplier]);
        return [$supplier, (int) $this->db->pdo()->lastInsertId(), new DepreciationEntryRepository($this->db)];
    }
}
