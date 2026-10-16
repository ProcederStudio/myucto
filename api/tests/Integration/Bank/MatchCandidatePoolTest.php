<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Match\MatchCandidateProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kandidáti návrhu párování příchozí platby: nejbližších 300 otevřených vydaných dokladů
 * podle splatnosti. Firma s tisíci otevřenými doklady je čte po indexovaných větvích
 * (jinak by pro každý pohyb řadila celé okno), menší firma jedním dotazem. Obě cesty
 * musí vrátit totéž včetně pořadí, i když se do 300 vejde jen část dokladů se stejnou
 * splatností a i u dokladů, které do okna padnou jen datem vystavení.
 *
 * Izolovaný rok 2097 pod existujícím supplierem, úklid v tearDown.
 */
#[Group('integration')]
final class MatchCandidatePoolTest extends TestCase
{
    private const MARKER = 'POOL2097-';

    private Connection $db;
    private MatchCandidateProvider $provider;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->provider = $c->get(MatchCandidateProvider::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->supplierId = (int) ($pdo->query("SELECT supplier_id FROM currencies WHERE id = {$this->currencyId}")->fetchColumn() ?: 0);
        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $this->supplierId === 0 || $this->clientId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí currency/client/user.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testBranchedPoolMatchesSingleQueryIncludingTiesAndIssueWindow(): void
    {
        $insert = $this->db->pdo()->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 100, 0, ?)"
        );
        $statuses = ['issued', 'sent', 'reminded', 'paid'];
        $n = 0;
        // 360 dokladů se stejnou splatností: do 300 se vejde jen část a rozhoduje id.
        for ($i = 0; $i < 360; $i++) {
            $this->seed($insert, $n++, 'invoice', $statuses[$i % 4], '2097-05-20', '2097-05-01');
        }
        // Rozptýlené splatnosti před i po dni platby, proformy.
        for ($i = 0; $i < 120; $i++) {
            $due = (new \DateTimeImmutable('2097-06-01'))->modify(($i % 2 === 0 ? '-' : '+') . ($i % 97) . ' days')->format('Y-m-d');
            $this->seed($insert, $n++, $i % 5 === 0 ? 'proforma' : 'invoice', $statuses[$i % 3], $due, '2097-03-01');
        }
        // Splatnost mimo okno, vystavení v něm.
        for ($i = 0; $i < 30; $i++) {
            $this->seed($insert, $n++, 'invoice', 'issued', '2098-03-01', '2097-06-0' . (1 + $i % 9));
        }

        $branched = new \ReflectionMethod($this->provider, 'issuedPool');
        $scan = new \ReflectionMethod($this->provider, 'issuedPoolScan');
        (new \ReflectionProperty($this->provider, 'largeIssuedPool'))->setValue($this->provider, [$this->supplierId => true]);

        foreach (['2097-05-20', '2097-05-25', '2097-06-01', '2097-08-15', '2097-09-10', '2097-02-01'] as $day) {
            $expected = $scan->invoke($this->provider, $this->supplierId, $day);
            self::assertSame($expected, $branched->invoke($this->provider, $this->supplierId, $day), "Kandidáti ke dni {$day}");
        }
        self::assertCount(300, $scan->invoke($this->provider, $this->supplierId, '2097-05-20'));
    }

    private function seed(\PDOStatement $insert, int $n, string $type, string $status, string $due, string $issue): void
    {
        $insert->execute([
            $type, self::MARKER . $n, $this->clientId, $this->supplierId, $issue, $issue, $due,
            $this->currencyId, $status, $this->userId,
        ]);
    }

    private function cleanup(): void
    {
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol LIKE ?')
            ->execute([$this->supplierId, self::MARKER . '%']);
    }
}
