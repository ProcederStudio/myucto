<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use DI\Container;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ActivityLogHashChain;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vystavení je po commitu hotové. Přepočet cache statistik smí selhat (deadlock 1213
 * při hromadném vystavování přes API), ale nesmí shodit volání dřív, než se faktura
 * zaúčtuje: přepočet běžel před auto-postem, takže po chybě zůstala vystavená
 * nezaúčtovaná faktura bez `accounting.auto_post_failed`.
 *
 * Pravidelná fakturace navíc po chybě za commitem vystavení neposunula plán, takže
 * příští běh cronu vystavil druhou fakturu za totéž období.
 */
#[Group('integration')]
final class IssueAfterCommitFailureTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Container $container;
    private Connection $db;
    private JournalEntryRepository $journal;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private string $today;
    /** @var list<bool> zda byla faktura v okamžiku přepočtu už zaúčtovaná */
    private array $postedAtRecompute = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        $this->today = date('Y-m-d');
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->journal = $this->container->get(JournalEntryRepository::class);
        $pdo = $this->db->pdo();

        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query(
            'SELECT id FROM vat_rates WHERE rate_percent = 21 AND is_reverse_charge = 0 ORDER BY valid_from DESC LIMIT 1'
        )->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        self::assertGreaterThan(0, min($source, $this->userId, $this->currencyId, $this->vatRateId, $czId));

        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            "UPDATE supplier SET stock_enabled = 0, accounting_mode = 'double_entry', auto_post_invoices = 1 WHERE id = ?"
        )->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by) VALUES (?, 'document.invoice', 'auto', ?)"
        )->execute([$this->supplierId, $this->userId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $year = (int) substr($this->today, 0, 4);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');

        // Bez e-mailu: automatické vystavení pak nemá komu fakturu poslat a SMTP se nevolá.
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, currency_default_id,
                 main_email, language, is_customer, is_vendor)
             VALUES (?, "Odběratel Souběh s.r.o.", "Testovací 1", "Praha", "11000", ?, ?, "", "cs", 1, 0)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        // Přepočet statistik po vystavení pokaždé skončí deadlockem.
        $stats = $this->getMockBuilder(StatsRecomputer::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['recomputeClient'])
            ->getMock();
        $stats->expects($this->atLeastOnce())->method('recomputeClient')->willReturnCallback(function (): void {
            $stmt = $this->db->pdo()->prepare("SELECT MAX(id) FROM invoices WHERE supplier_id = ? AND status <> 'draft'");
            $stmt->execute([$this->supplierId]);
            $issued = (int) $stmt->fetchColumn();
            if ($issued > 0) {
                $this->postedAtRecompute[] = $this->journal->findBySource($this->supplierId, 'invoice', $issued) !== null;
            }
            $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
            $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            throw $e;
        });
        $this->container->set(StatsRecomputer::class, $stats);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sid = $this->supplierId;
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare(
            'DELETE ti FROM recurring_invoice_template_items ti JOIN recurring_invoice_templates t ON t.id = ti.template_id WHERE t.supplier_id = ?'
        )->execute([$sid]);
        $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
        $this->db->close();
    }

    public function testIssueActionPostsInvoiceDespiteStatsFailure(): void
    {
        $id = $this->draft();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/issue')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = ($this->container->get(IssueInvoiceAction::class))($request, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertIssuedAndPosted($id);
    }

    public function testAutomaticIssuePostsInvoiceDespiteStatsFailure(): void
    {
        $id = $this->draft();

        $result = $this->container->get(AutoIssueAndSendService::class)->run($id, $this->userId, '127.0.0.1', 'phpunit');

        self::assertTrue($result['issued']);
        $this->assertIssuedAndPosted($id);
    }

    public function testRecurringIssuePostsInvoiceDespiteStatsFailure(): void
    {
        $templateId = $this->template();
        $generator = $this->container->get(RecurringInvoiceGenerator::class);

        $result = $generator->generate($templateId, null, $this->userId, '127.0.0.1', 'phpunit');

        self::assertTrue($result['issued']);
        self::assertNotSame($this->today, $this->templateNextRun($templateId), 'Plán se po vystavení posune.');
        $this->assertIssuedAndPosted($result['invoice_id']);
    }

    /**
     * Chyba po commitu vystavení (tady zápis do auditní stopy) se ohlásí, ale plán se
     * posune: další běh cronu nesmí vystavit druhou fakturu za totéž období.
     */
    public function testRecurringFailureAfterIssueStillAdvancesSchedule(): void
    {
        $templateId = $this->template();
        $failing = new class ($this->db, $this->container->get(ActivityLogHashChain::class)) extends ActivityLogger {
            public int $failures = 1;

            public function log(
                string $action,
                ?int $userId = null,
                ?string $entityType = null,
                ?int $entityId = null,
                ?array $payload = null,
                ?string $ip = null,
                ?string $userAgent = null,
                ?int $supplierId = null,
            ): void {
                if ($action === 'invoice.issued' && $this->failures > 0) {
                    $this->failures--;
                    throw new \RuntimeException('Simulovaná chyba po commitu vystavení.');
                }
                parent::log($action, $userId, $entityType, $entityId, $payload, $ip, $userAgent, $supplierId);
            }
        };
        $this->container->set(ActivityLogger::class, $failing);
        $generator = $this->container->get(RecurringInvoiceGenerator::class);

        $failure = null;
        try {
            $generator->generate($templateId, null, $this->userId, '127.0.0.1', 'phpunit');
        } catch (\RuntimeException $e) {
            $failure = $e;
        }
        self::assertNotNull($failure, 'Chyba po vystavení se má ohlásit volajícímu.');
        self::assertSame(0, $failing->failures);
        self::assertSame(1, $this->templateInvoiceCount($templateId, 'issued'));
        self::assertNotSame($this->today, $this->templateNextRun($templateId), 'Plán se musí posunout, jinak příští běh vystaví fakturu znovu.');

        // Příští běh cronu: další období, ne druhá faktura za to dnešní.
        $next = $generator->generate($templateId, null, $this->userId, '127.0.0.1', 'phpunit');
        self::assertNotSame($this->today, $this->invoiceIssueDate($next['invoice_id']));
        self::assertSame(1, $this->templateInvoiceCountOn($templateId, $this->today));
    }

    private function assertIssuedAndPosted(int $id): void
    {
        self::assertNotSame([], $this->postedAtRecompute, 'Přepočet statistik neproběhl, test nic neověřil.');
        self::assertNotContains(false, $this->postedAtRecompute, 'Přepočet statistik běžel před zaúčtováním.');
        $status = $this->db->pdo()->query('SELECT status FROM invoices WHERE id = ' . $id)->fetchColumn();
        self::assertSame('issued', $status);
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'invoice', $id), 'Vystavená faktura se má zaúčtovat.');
        self::assertSame(0, $this->auditCount('accounting.auto_post_failed', $id));
    }

    private function draft(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, language, status, total_without_vat, total_vat, total_with_vat, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, ?, "cs", "draft", 100, 21, 121, ?)'
        )->execute([$this->supplierId, $this->clientId, $this->today, $this->today, $this->today, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Testovací plnění", 1, "ks", 100, ?, 21, 100, 21, 121, 0)'
        )->execute([$id, $this->vatRateId]);

        return $id;
    }

    private function template(): int
    {
        $repo = $this->container->get(RecurringTemplateRepository::class);
        $id = $repo->create([
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'project_id'       => null,
            'name'             => 'Souběh (PHPUnit)',
            'frequency'        => 'monthly',
            'day_of_month'     => null,
            'end_of_month'     => false,
            'anchor_date'      => $this->today,
            'next_run_date'    => $this->today,
            'end_date'         => null,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'reverse_charge'   => false,
            'payment_due_days' => 14,
            'note_above_items' => null,
            'note_below_items' => null,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => true,
            'auto_send_email'  => false,
            'status'           => 'active',
        ], $this->userId);
        $repo->replaceItems($id, [[
            'description'            => 'Paušál',
            'quantity'               => 1.0,
            'unit'                   => 'měs',
            'unit_price_without_vat' => 500.00,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);

        return $id;
    }

    private function templateNextRun(int $templateId): string
    {
        return (string) $this->db->pdo()->query('SELECT next_run_date FROM recurring_invoice_templates WHERE id = ' . $templateId)->fetchColumn();
    }

    private function templateInvoiceCount(int $templateId, string $status): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE recurring_template_id = ? AND status = ?');
        $stmt->execute([$templateId, $status]);

        return (int) $stmt->fetchColumn();
    }

    private function templateInvoiceCountOn(int $templateId, string $issueDate): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE recurring_template_id = ? AND issue_date = ?');
        $stmt->execute([$templateId, $issueDate]);

        return (int) $stmt->fetchColumn();
    }

    private function invoiceIssueDate(int $id): string
    {
        return (string) $this->db->pdo()->query('SELECT issue_date FROM invoices WHERE id = ' . $id)->fetchColumn();
    }

    private function auditCount(string $action, int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_type = 'invoice' AND entity_id = ?"
        );
        $stmt->execute([$this->supplierId, $action, $invoiceId]);

        return (int) $stmt->fetchColumn();
    }
}
