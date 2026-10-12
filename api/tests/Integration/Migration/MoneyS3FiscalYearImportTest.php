<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\MoneyS3\JournalImporter;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Hospodářský rok v převodu z Money S3: adresář ROK zálohy = jeden účetní rok Money =
 * jedno účetní období firmy podle jejího nastavení „Účetní rok začíná".
 */
#[Group('integration')]
final class MoneyS3FiscalYearImportTest extends MoneyS3ImportTestCase
{
    public function testFiscalCompanyTakesFiscalAgendaAsFiscalPeriods(): void
    {
        $supplierId = $this->fiscalSupplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->fiscalBackup(), new ImportOptions(ImportOptions::MODE_IMPORT, true));

        $steps = array_column($protocol->toArray()['steps'], null, 'key');
        self::assertSame([], self::errors($steps['preflight']), $this->explain($protocol));
        self::assertSame([], self::errors($steps['journal']), $this->explain($protocol));
        self::assertSame([
            [2024, '2024-08-01', '2025-07-31'],
            [2025, '2025-08-01', '2026-07-31'],
        ], $this->periods($supplierId));

        // FP24001 z 10. 2. 2024 posunutý na 10. 9. 2024, FP25001 z 15. 1. 2025 na 15. 8. 2025.
        self::assertSame(['2024-09-10', 2024], $this->entry($supplierId, 'FP24001', '2024-09-10'));
        self::assertSame(['2025-08-15', 2025], $this->entry($supplierId, 'FP25001'));
        self::assertSame(['2026-07-31', 2025], $this->entry($supplierId, 'BV25001', '2026-07-31'), 'poslední den hospodářského roku');
        self::assertSame(['2025-08-01', 2025], $this->entry($supplierId, 'ID25002', null, true),
            'doklad knihy ROK.002 s datem z předchozího roku jde k prvnímu dni období');
        self::assertSame('2024-08-01', $this->openingDate($supplierId, 2024));
        self::assertSame('closed', array_column($protocol->get('closing'), null, 'year')[2024]['status'] ?? null, $this->explain($protocol));
    }

    public function testCalendarCompanyStopsFiscalAgendaWithSuggestedMonth(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, $this->fiscalBackup(), new ImportOptions(ImportOptions::MODE_IMPORT, true));

        $preflight = array_column($protocol->toArray()['steps'], null, 'key')['preflight'];
        $mismatch = array_values(array_filter($preflight['messages'], static fn (array $m): bool => $m['code'] === 'fiscal_year_mismatch'));
        self::assertNotSame([], $mismatch, $this->explain($protocol));
        self::assertSame(8, $mismatch[0]['context']['suggested_start_month']);
        self::assertStringContainsString('srpen', $mismatch[0]['text'] ?? $mismatch[0]['message'] ?? '');
        self::assertSame([], $this->periods($supplierId), 'zastavený převod nezakládá období');
    }

    /**
     * Firma vzniklá během hospodářského roku: první období začíná dnem vzniku (první zápis
     * deníku, nebo výslovně zadané datum) a končí koncem hospodářského roku.
     */
    public function testFoundingDateShortensFirstFiscalPeriod(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/founded.lz', SyntheticAgenda::filesFiscalYearFromAugust(false));
        $backup = Ms3Backup::extract($this->tmp . '/founded.lz', $this->tmp . '/founded');
        $journal = $this->container(JournalImporter::class);
        $calendar = new FiscalCalendar(8);

        $plan = $journal->plan($backup, new ImportOptions(), $calendar);
        self::assertSame(['2024-09-10', '2025-07-31'], [$plan[0]['starts_on'], $plan[0]['ends_on']]);
        self::assertSame(['2025-08-01', '2026-07-31'], [$plan[1]['starts_on'], $plan[1]['ends_on']]);

        $explicit = $journal->plan($backup, new ImportOptions(ImportOptions::MODE_DRY_RUN, true, '2024-08-20'), $calendar);
        self::assertSame('2024-08-20', $explicit[0]['starts_on']);
        $outside = $journal->plan($backup, new ImportOptions(ImportOptions::MODE_DRY_RUN, true, '2025-08-20'), $calendar);
        self::assertSame('2024-08-01', $outside[0]['starts_on'], 'datum mimo první období se nepoužije, období začíná začátkem roku');

        $supplierId = $this->fiscalSupplier();
        $this->importer->run($supplierId, $this->userId, $backup, new ImportOptions(ImportOptions::MODE_IMPORT, true));
        self::assertSame([2024, '2024-09-10', '2025-07-31'], $this->periods($supplierId)[0] ?? null);
    }

    private function fiscalSupplier(): int
    {
        $id = $this->supplier();
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 8 WHERE id = ?')->execute([$id]);
        return $id;
    }

    private function fiscalBackup(): Ms3Backup
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/fiscal.lz', SyntheticAgenda::filesFiscalYearFromAugust());
        return Ms3Backup::extract($this->tmp . '/fiscal.lz', $this->tmp . '/fiscal-' . bin2hex(random_bytes(3)));
    }

    /** @return list<array{0:int,1:string,2:string}> */
    private function periods(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT fiscal_year, starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? ORDER BY fiscal_year');
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => [(int) $r['fiscal_year'], (string) $r['starts_on'], (string) $r['ends_on']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{0:string,1:int}|null datum zápisu a label jeho období */
    private function entry(int $supplierId, string $documentNo, ?string $entryDate = null, bool $moved = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT e.entry_date, e.document_date, p.fiscal_year FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND e.document_no = ?' . ($entryDate !== null ? ' AND e.entry_date = ?' : '') . ' ORDER BY e.entry_date LIMIT 1'
        );
        $stmt->execute(array_merge([$supplierId, $documentNo], $entryDate !== null ? [$entryDate] : []));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if ($moved) {
            self::assertNotNull($row['document_date'], 'původní datum zůstává jako datum dokladu');
        }
        return [(string) $row['entry_date'], (int) $row['fiscal_year']];
    }

    private function openingDate(int $supplierId, int $year): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.entry_date FROM journal_entries e JOIN accounting_periods p ON p.id = e.period_id
              WHERE e.supplier_id = ? AND e.source_type = 'opening' AND p.fiscal_year = ? ORDER BY e.id LIMIT 1"
        );
        $stmt->execute([$supplierId, $year]);
        $date = $stmt->fetchColumn();
        return $date === false ? null : (string) $date;
    }

    /**
     * @param array<string,mixed> $step
     * @return list<string>
     */
    private static function errors(array $step): array
    {
        return array_values(array_map(
            static fn (array $m): string => (string) $m['code'],
            array_filter((array) ($step['messages'] ?? []), static fn (array $m): bool => ($m['level'] ?? '') === 'error'),
        ));
    }
}
