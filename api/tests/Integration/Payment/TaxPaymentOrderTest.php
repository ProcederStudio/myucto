<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payment;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Service\Payment\PaymentOrderService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Daně firmy v platebních příkazech: DPH z podaného přiznání a záloha na daň z příjmů.
 * Účet FÚ, VS i částku odvozuje server, klient posílá jen ID závazku.
 */
#[Group('integration')]
final class TaxPaymentOrderTest extends TestCase
{
    private const PERIOD_YEAR = 2099;
    private const VS = '24681357';

    private Connection $db;
    private PaymentOrderService $service;
    private PaymentOrderRepository $orders;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private ?string $originalOffice = null;
    private int $submissionId = 0;
    private int $advanceId = 0;
    /** @var list<int> */
    private array $orderIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(PaymentOrderService::class);
            $this->orders = $container->get(PaymentOrderRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $account = $pdo->query(
            "SELECT id, supplier_id FROM currencies
              WHERE code = 'CZK' AND is_active = 1 AND account_number IS NOT NULL AND account_number <> ''
                AND bank_code IS NOT NULL AND bank_code <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            $this->markTestSkipped('Chybí CZK měna s bankovním účtem.');
        }
        $this->supplierId = (int) $account['supplier_id'];
        $this->currencyId = (int) $account['id'];
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $office = $pdo->query('SELECT financial_office_code FROM supplier WHERE id = ' . $this->supplierId)->fetchColumn();
        $this->originalOffice = $office === false || $office === null ? null : (string) $office;

        $this->cleanup();
        $pdo->prepare("UPDATE supplier SET financial_office_code = '454' WHERE id = ?")->execute([$this->supplierId]);

        $xml = '<Pisemnost><DPHDP3><VetaD dapdph_forma="B" rok="' . self::PERIOD_YEAR . '" ctvrt="1"/>'
            . '<VetaP c_ufo="454" dic="' . self::VS . '"/>'
            . '<Veta6 dan_zocelk="1500" odp_zocelk="500" dano_da="1000"/></DPHDP3></Pisemnost>';
        $pdo->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, period_quarter,
                                          xml_content, xml_size_bytes, xml_sha256, validation_status, status, submitted_at)
             VALUES (?, 'dphdp3', ?, NULL, 1, ?, ?, SHA2(RAND(), 256), 'passed', 'submitted', NOW())"
        )->execute([$this->supplierId, self::PERIOD_YEAR, $xml, strlen($xml)]);
        $this->submissionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO tax_advance_schedules (supplier_id, taxpayer_type, advance_kind, period_year, seq_no,
                                                due_date, amount, variable_symbol, status)
             VALUES (?, 'po', 'tax', ?, 9, ?, 2500, ?, 'planned')"
        )->execute([$this->supplierId, self::PERIOD_YEAR, date('Y-m-d', strtotime('+30 days')), self::VS]);
        $this->advanceId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            $this->db->pdo()->prepare('UPDATE supplier SET financial_office_code = ? WHERE id = ?')
                ->execute([$this->originalOffice, $this->supplierId]);
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $orderIds = $pdo->prepare(
            'SELECT DISTINCT poi.payment_order_id FROM payment_order_items poi
               LEFT JOIN tax_submissions ts ON ts.id = poi.tax_submission_id
               LEFT JOIN tax_advance_schedules tas ON tas.id = poi.tax_advance_schedule_id
              WHERE (ts.supplier_id = ? AND ts.period_year = ?) OR (tas.supplier_id = ? AND tas.period_year = ?)'
        );
        $orderIds->execute([$this->supplierId, self::PERIOD_YEAR, $this->supplierId, self::PERIOD_YEAR]);
        $ids = array_merge($this->orderIds, array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN) ?: []));
        foreach (array_unique($ids) as $orderId) {
            $pdo->prepare('DELETE FROM payment_order_items WHERE payment_order_id = ?')->execute([$orderId]);
            $pdo->prepare('DELETE FROM payment_orders WHERE id = ?')->execute([$orderId]);
            $pdo->prepare("DELETE FROM activity_log WHERE entity_type = 'payment_order' AND entity_id = ?")->execute([$orderId]);
        }
        $this->orderIds = [];
        $pdo->prepare("DELETE FROM tax_submissions WHERE supplier_id = ? AND period_year = ? AND form_code = 'dphdp3'")
            ->execute([$this->supplierId, self::PERIOD_YEAR]);
        $pdo->prepare('DELETE FROM tax_advance_schedules WHERE supplier_id = ? AND period_year = ?')
            ->execute([$this->supplierId, self::PERIOD_YEAR]);
    }

    /** @return array<string,array<string,mixed>> */
    private function taxCandidates(): array
    {
        $out = [];
        foreach ($this->service->candidates($this->supplierId, 'CZK')['tax_candidates'] as $candidate) {
            $out[$candidate['key']] = $candidate;
        }
        return $out;
    }

    public function testCandidatesCarryOfficeAccountAndVariableSymbol(): void
    {
        $candidates = $this->taxCandidates();

        $vat = $candidates['vat:' . $this->submissionId] ?? null;
        self::assertNotNull($vat, 'Podané přiznání s daní k úhradě patří mezi kandidáty.');
        self::assertSame(1000.0, $vat['amount']);
        self::assertSame('705-77627311', $vat['account_number']);
        self::assertSame('0710', $vat['bank_code']);
        self::assertSame(self::VS, $vat['variable_symbol']);
        self::assertSame('2099-04-27', $vat['due_date']);

        $advance = $candidates['advance:' . $this->advanceId] ?? null;
        self::assertNotNull($advance);
        self::assertSame(2500.0, $advance['amount']);
        self::assertSame('7704-77627311', $advance['account_number']);
        self::assertSame(self::VS, $advance['variable_symbol']);

        self::assertSame([], $this->service->candidates($this->supplierId, 'EUR')['tax_candidates']);
    }

    public function testTaxOrderWritesAboLinesAndMarksCandidatesOrdered(): void
    {
        $created = $this->service->create($this->supplierId, [
            'tax_submission_ids' => [$this->submissionId, 999999999],
            'tax_advance_ids'    => [$this->advanceId],
            'payer_currency_id'  => $this->currencyId,
            'payment_date'       => date('Y-m-d'),
        ], $this->userId);
        $orderId = $created['order_id'];
        $this->orderIds[] = $orderId;

        self::assertSame([['id' => 999999999, 'reason' => 'nothing_to_pay', 'document' => 'tax_vat']], $created['skipped']);
        self::assertSame(3500.0, $created['view']['total_amount']);
        $items = $created['view']['items'];
        self::assertSame($this->submissionId, $items[0]['tax_submission_id']);
        self::assertSame($this->advanceId, $items[1]['tax_advance_schedule_id']);
        self::assertSame('Finanční úřad pro Plzeňský kraj', $items[0]['payee_name']);

        $abo = (string) $this->service->download($orderId, $this->supplierId, 'abo')['bytes'];
        self::assertStringContainsString('000705-0077627311 000000100000 ' . self::VS . ' 07100000', $abo);
        self::assertStringContainsString('007704-0077627311 000000250000 ' . self::VS . ' 07100000', $abo);
        self::assertTrue($this->orders->allItemsStillPayable($orderId, $this->supplierId, 'CZK'));

        $candidates = $this->taxCandidates();
        self::assertNotNull($candidates['vat:' . $this->submissionId]['ordered_at']);
        self::assertNotNull($candidates['advance:' . $this->advanceId]['ordered_at']);

        self::assertSame('deleted', $this->service->delete($orderId, $this->supplierId));
        self::assertNull($this->taxCandidates()['vat:' . $this->submissionId]['ordered_at']);
    }

    public function testOrderNeedsKnownTaxOffice(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET financial_office_code = NULL WHERE id = ?')->execute([$this->supplierId]);

        $created = $this->service->create($this->supplierId, [
            'tax_submission_ids' => [$this->submissionId],
            'tax_advance_ids'    => [$this->advanceId],
            'payer_currency_id'  => $this->currencyId,
        ], $this->userId);
        $this->orderIds[] = $created['order_id'];

        // DPH nese úřad v XML, záloha bez kódu FÚ ve firmě účet nemá.
        self::assertCount(1, $created['view']['items']);
        self::assertSame([['id' => $this->advanceId, 'reason' => 'no_account', 'document' => 'tax_advance']], $created['skipped']);
    }
}
