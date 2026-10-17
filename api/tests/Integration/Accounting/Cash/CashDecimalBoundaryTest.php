<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\CashDocumentRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashDecimalBoundaryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private CashDocumentService $service;
    private CashDocumentRepository $documents;
    private int $supplier;
    private int $register;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(CashDocumentService::class);
        $this->documents = $container->get(CashDocumentRepository::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $this->supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier);
        (new AccountingPeriodRepository($this->db))->create($this->supplier, 2097, '2097-01-01', '2097-12-31');
        $pdo->prepare("INSERT INTO cash_registers (supplier_id,name,account_code) VALUES (?,'Synthetic decimal','211')")->execute([$this->supplier]);
        $this->register = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function amounts(): array
    {
        return [['0.01'], ['49.99'], [49.99], ['1000000000000.01'], ['1234567890123.45'],
            [1234567890123.45], ['9999999999999.99'], ['1.23456789012345e12']];
    }

    #[DataProvider('amounts')]
    public function testNativeCreateUpdateReadPostReverseRetainEveryCent(string|float $amount): void
    {
        $expected = JournalLineAmount::decimal($amount);
        $saved = $this->service->create($this->supplier, $this->body($amount), null);
        $id = $saved['id'];
        self::assertSame($expected, $this->rawAmount($id));
        $this->service->updateDraft($this->supplier, $id, ['total_amount' => '1.00']);
        $this->service->updateDraft($this->supplier, $id, ['total_amount' => $amount]);
        self::assertSame($expected, $this->rawAmount($id));
        $this->service->updateDraft($this->supplier, $id, ['description' => 'Synthetic unchanged amount']);
        self::assertSame($expected, $this->rawAmount($id));
        $detail = $this->service->get($this->supplier, $id);
        self::assertIsFloat($detail['total_amount']);
        self::assertSame($expected, JournalLineAmount::decimal($detail['total_amount']));
        $posted = $this->service->post($this->supplier, $id, null);
        $original = $this->lines((int) $posted['journal_entry_id']);
        self::assertCount(2, $original);
        self::assertSame([$expected, $expected], array_column($original, 'amount'));
        $again = $this->service->post($this->supplier, $id, null);
        self::assertSame($posted['journal_entry_id'], $again['journal_entry_id']);
        $this->service->reverse($this->supplier, $id, ['reason' => 'Synthetic exact mirror', 'entry_date' => '2097-06-16'], null);
        $doc = $this->service->get($this->supplier, $id);
        $mirror = $this->lines((int) $doc['reversal_entry_id']);
        self::assertSame(array_map(JournalLineAmount::reversal(...), $original), $mirror);
        self::assertTrue($this->db->pdo()->inTransaction());
        $this->db->pdo()->rollBack();
        self::assertSame(false, $this->rawAmount($id));
    }

    public static function repositoryAmounts(): array
    {
        return [['1234567890123.45'], ['9999999999999.99'], ['0.01']];
    }

    #[DataProvider('repositoryAmounts')]
    public function testRepositorySerializesMoneyBeforePdoWithoutChangingReadTypes(string $amount): void
    {
        $data = $this->body('1.00') + ['status' => 'draft'];
        $data['currency_code'] = 'EUR';
        $data['amount_foreign'] = $amount;
        $data['total_amount'] = $amount;
        $data['fx_rate'] = 1.0;
        $id = $this->documents->insert($this->supplier, $data, null);
        foreach (['insert', 'update'] as $mode) {
            if ($mode === 'update') $this->documents->updateHeader($this->supplier, $id, $data);
            $stmt = $this->db->pdo()->prepare('SELECT total_amount, amount_foreign FROM cash_documents WHERE supplier_id=? AND id=?');
            $stmt->execute([$this->supplier, $id]);
            self::assertSame(['total_amount' => $amount, 'amount_foreign' => $amount], $stmt->fetch(PDO::FETCH_ASSOC));
            self::assertIsFloat($this->documents->find($this->supplier, $id)['amount_foreign']);
        }
        $this->documents->replaceVatLines($id, [['vat_rate' => 0, 'base_amount' => $amount, 'vat_amount' => '0.01']]);
        $stmt = $this->db->pdo()->prepare('SELECT base_amount,vat_amount FROM cash_document_vat_lines WHERE cash_document_id=?');
        $stmt->execute([$id]);
        self::assertSame(['base_amount' => $amount, 'vat_amount' => '0.01'], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testNegativeCashAmountStillRefusedAndWrongTenantCannotPost(): void
    {
        try {
            $this->service->create($this->supplier, $this->body('-0.01'), null);
            self::fail('Negative cash accepted.');
        } catch (CashException $e) {
            self::assertSame('validation', $e->errorCode);
        }
        $saved = $this->service->create($this->supplier, $this->body('1234567890123.45'), null);
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplier);
        try {
            $this->service->post($other, $saved['id'], null);
            self::fail('Wrong tenant accepted.');
        } catch (CashException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame('draft', $this->service->get($this->supplier, $saved['id'])['status']);
        self::assertSame('1234567890123.45', $this->rawAmount($saved['id']));
    }

    public function testVatPersistenceDoesNotRoundAlreadyCalculatedAmounts(): void
    {
        $saved = $this->service->create($this->supplier, $this->body('1.00'), null);
        $this->documents->replaceVatLines($saved['id'], [['vat_rate' => 21, 'base_amount' => '1234567890123.45', 'vat_amount' => '1000000000000.01']]);
        $stmt = $this->db->pdo()->prepare('SELECT base_amount,vat_amount FROM cash_document_vat_lines WHERE cash_document_id=?');
        $stmt->execute([$saved['id']]);
        self::assertSame(['base_amount' => '1234567890123.45', 'vat_amount' => '1000000000000.01'], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testOutgoingCashMirrorRetainsActualDimensionAssignmentsAndSplits(): void
    {
        $container = Bootstrap::buildContainer();
        $dimensions = $container->get(DimensionService::class);
        $assignments = $container->get(DimensionAssignmentRepository::class);
        $dimensions->setEnabled($this->supplier, true);
        $types = $dimensions->ensureDefaultTypes($this->supplier, ['projekt', 'stredisko']);
        $center = $dimensions->createValue($this->supplier, $types['cost_center'], ['code' => 'DECIMAL', 'name' => 'Synthetic center'])['id'];
        $project = $dimensions->createValue($this->supplier, $types['project'], ['code' => 'DECIMAL', 'name' => 'Synthetic project'])['id'];
        $custom = $dimensions->createType($this->supplier, ['code' => 'decimal_split', 'name' => 'Synthetic split'])['id'];
        $a = $dimensions->createValue($this->supplier, $custom, ['code' => 'A', 'name' => 'Synthetic A'])['id'];
        $b = $dimensions->createValue($this->supplier, $custom, ['code' => 'B', 'name' => 'Synthetic B'])['id'];
        $body = $this->body('1234567890123.45');
        $body['doc_type'] = 'out';
        $id = $this->service->create($this->supplier, $body, null)['id'];
        $dimensions->saveDocument($this->supplier, 'cash_document', $id, [$types['cost_center'] => $center, $types['project'] => $project], [], false,
            [0 => [$custom => [['value_id' => $a, 'share' => 0.4], ['value_id' => $b, 'share' => 0.6]]]]);
        $entry = (int) $this->service->post($this->supplier, $id, null)['journal_entry_id'];
        $original = $this->lines($entry);
        self::assertSame(['1234567890123.45', '1234567890123.45'], array_column($original, 'amount'));
        $originalDims = array_values($assignments->entryLineDimensions($this->supplier, $entry));
        $originalSplits = array_values($assignments->entryLineSplits($this->supplier, $entry));
        self::assertCount(2, $originalDims);
        self::assertCount(2, $originalSplits);
        self::assertSame([$custom => [$a => 0.4, $b => 0.6]], $originalSplits[0]);
        $this->service->reverse($this->supplier, $id, ['reason' => 'Synthetic outgoing mirror', 'entry_date' => '2097-06-16'], null);
        $reversal = (int) $this->service->get($this->supplier, $id)['reversal_entry_id'];
        self::assertSame(array_map(JournalLineAmount::reversal(...), $original), $this->lines($reversal));
        self::assertSame($originalDims, array_values($assignments->entryLineDimensions($this->supplier, $reversal)));
        self::assertSame($originalSplits, array_values($assignments->entryLineSplits($this->supplier, $reversal)));
    }

    private function body(string|float $amount): array
    {
        return ['register_id' => $this->register, 'doc_type' => 'in', 'purpose' => 'other',
            'issue_date' => '2097-06-15', 'description' => 'Synthetic decimal', 'vat_mode' => 'none',
            'total_amount' => $amount, 'counter_account_code' => '379', 'post' => false];
    }

    private function rawAmount(int $id): string|false
    {
        $stmt = $this->db->pdo()->prepare('SELECT total_amount FROM cash_documents WHERE supplier_id=? AND id=?');
        $stmt->execute([$this->supplier, $id]);
        return $stmt->fetchColumn();
    }

    private function lines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM journal_entry_lines WHERE entry_id=? AND supplier_id=? ORDER BY line_no');
        $stmt->execute([$entryId, $this->supplier]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $line): array {
            unset($line['id'], $line['entry_id'], $line['created_at']);
            $line['is_red_storno'] = !empty($line['is_red_storno']);
            return $line;
        }, $lines);
    }
}
