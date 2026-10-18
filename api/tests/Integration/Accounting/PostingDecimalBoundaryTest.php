<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Expense\PurchaseDiscountReclass;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PostingDecimalBoundaryTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private PostingService $posting;
    private int $supplier;
    private int $actor;
    private JournalEntryRepository $journal;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $this->supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $this->actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier);
        (new AccountingPeriodRepository($this->db))->create($this->supplier, 2097, '2097-01-01', '2097-12-31');
        $column = $pdo->query("SELECT NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='journal_entry_lines' AND COLUMN_NAME='amount'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(15, (int) $column['NUMERIC_PRECISION']);
        self::assertSame(2, (int) $column['NUMERIC_SCALE']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function amounts(): array
    {
        return [['9.99'], ['999999999999.99'], ['1000000000000.01'], ['1234567890123.45'], ['9999999999999.99']];
    }

    #[DataProvider('amounts')]
    public function testPostingPreservesEveryCentSupportedByLedgerDecimal(string $amount): void
    {
        $entry = $this->posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => '2097-06-15', 'document_no' => 'SYNTHETIC-DECIMAL', 'posted_by' => $this->actor, 'user_id' => $this->actor]);
        $stmt = $this->db->pdo()->prepare('SELECT amount FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no');
        $stmt->execute([$this->supplier, $entry]);
        self::assertSame([$amount, $amount], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function writePaths(): array
    {
        return [['insert'], ['full'], ['partial'], ['reverse'], ['red_reverse'], ['exponent']];
    }

    public static function invalidAmounts(): array
    {
        return [[0], [-1], ['-0.01'], [NAN], [INF], [-INF], ['1e10000'], [null], [true], [[]]];
    }

    public function testForeignTraceBackfillPreservesEveryCent(): void
    {
        $entry = $this->posting->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => '1000000000000.01'],
            ['account_code' => '602', 'side' => 'credit', 'amount' => '1000000000000.01'],
        ], ['entry_date' => '2097-06-15', 'document_no' => 'SYNTHETIC-FX-TRACE']);
        $service = Bootstrap::buildContainer()->get(PurchaseDiscountReclass::class);
        $method = new \ReflectionMethod(PurchaseDiscountReclass::class, 'fillForeignTrace');
        self::assertSame(1, $method->invoke($service, $this->supplier, $entry, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 1000000000000.01,
                'currency_code' => 'EUR', 'fx_rate' => 1.0, 'amount_foreign' => 1000000000000.01],
        ], true));
        $stmt = $this->db->pdo()->prepare("SELECT amount_foreign FROM journal_entry_lines WHERE entry_id=? AND supplier_id=? AND side='debit'");
        $stmt->execute([$entry, $this->supplier]);
        self::assertSame('1000000000000.01', $stmt->fetchColumn());
    }

    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsStillFailBeforeWriting(mixed $amount): void
    {
        try {
            $this->posting->postDocument($this->supplier, 'manual', null, [
                ['account_code' => '311', 'side' => 'debit', 'amount' => $amount],
                ['account_code' => '602', 'side' => 'credit', 'amount' => $amount],
            ], ['entry_date' => '2097-06-15']);
            self::fail('Invalid amount was accepted.');
        } catch (PostingException $e) {
            self::assertSame('nonpositive_amount', $e->errorCode);
        }
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?');
        $count->execute([$this->supplier]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    #[DataProvider('writePaths')]
    public function testAllWritersPreserveCzkAndForeignAmountsAndNumericReadShape(string $path): void
    {
        $amount = '1234567890123.45';
        $foreign = '1000000000000.01';
        $meta = ['entry_date' => '2097-06-15', 'document_no' => 'SYNTHETIC-DECIMAL-PATH', 'posted_by' => $this->actor, 'user_id' => $this->actor];
        $lines = static fn (float|string $value, float|string $fx, bool $red = false): array => [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $value, 'currency_code' => 'EUR', 'fx_rate' => '1.234567', 'amount_foreign' => $fx, 'is_red_storno' => $red],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $value, 'currency_code' => 'EUR', 'fx_rate' => '1.234567', 'amount_foreign' => $fx, 'is_red_storno' => $red],
        ];
        if ($path === 'insert' || $path === 'exponent') {
            $entry = $this->posting->postDocument($this->supplier, 'manual', 17001,
                $lines($path === 'exponent' ? '1.23456789012345e12' : (float) $amount, (float) $foreign), $meta);
        } else {
            $entry = $this->posting->postDocument($this->supplier, 'manual', 17001, $lines('1.00', '1.00', $path === 'red_reverse'), $meta);
            if ($path === 'full') {
                self::assertSame($entry, $this->posting->postDocument($this->supplier, 'manual', 17001, $lines($amount, (float) $foreign), $meta));
            } elseif ($path === 'partial') {
                $current = $this->journal->find($entry, $this->supplier);
                $this->posting->replaceEntryLines($this->supplier, 'manual', 17001, $current['row_version'],
                    array_column($current['lines'], 'id'), $lines($amount, (float) $foreign), $meta);
            } else {
                // Samostatný důkaz storna nad přesným uloženým zdrojem.
                $this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=?, amount_foreign=? WHERE entry_id=? AND supplier_id=?')->execute([$amount, $foreign, $entry, $this->supplier]);
                $entry = $this->posting->reverse($this->supplier, $entry, $meta);
            }
        }
        $stmt = $this->db->pdo()->prepare('SELECT amount, amount_foreign, side, is_red_storno FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no');
        $stmt->execute([$this->supplier, $entry]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([$amount, $amount], array_column($rows, 'amount'));
        self::assertSame([$foreign, $foreign], array_column($rows, 'amount_foreign'));
        self::assertSame($path === 'reverse' ? ['credit', 'debit'] : ['debit', 'credit'], array_column($rows, 'side'));
        self::assertSame([0, 0], array_map('intval', array_column($rows, 'is_red_storno')));
        $read = $this->journal->find($entry, $this->supplier);
        self::assertIsFloat($read['lines'][0]['amount']);
        self::assertIsFloat($read['lines'][0]['amount_foreign']);
        $json = json_decode(json_encode($read, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsFloat($json['lines'][0]['amount']);
        self::assertSame((float) $amount, $json['lines'][0]['amount']);
        self::assertSame((float) $foreign, $json['lines'][0]['amount_foreign']);
    }
}
