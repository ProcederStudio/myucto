<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierImporter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class FxImportedHomeSettlementTest extends TestCase
{
    private Connection $db;
    private int $supplierId;
    private int $documentId;
    private int $bankId;
    private string $directory;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $currency, $vat, $user));
        $pdo->prepare('INSERT INTO supplier (company_name,street,city,zip,country_id,email,ic,dic,is_vat_payer,vat_period,default_currency_id,default_vat_rate_id,accounting_mode) VALUES (?,"Účetní 1","Brno","60200",?,"fx-import@example.invalid",?,?,1,"monthly",?,?,"tax_evidence")')
            ->execute([SyntheticPremierBackup::NAME, $country, SyntheticPremierBackup::ICO, 'CZ' . SyntheticPremierBackup::ICO, $currency, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES (?,'CZK','CZK','Kč','Česká koruna','Czech Koruna',2,1,1)")->execute([$this->supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $pdo->lastInsertId(), $this->supplierId]);
        $this->directory = sys_get_temp_dir() . '/fx_import_home_' . bin2hex(random_bytes(6));
        SyntheticPremierBackup::writeDir($this->directory);
        $protocol = $container->get(PremierImporter::class)->run($this->supplierId, $user, PremierBackup::open($this->directory), SyntheticPremierBackup::YEAR1, false);
        self::assertFalse($protocol->hasErrors());
        $stmt = $pdo->prepare("SELECT id FROM purchase_invoices WHERE supplier_id=? AND payment_variable_symbol='7004'");
        $stmt->execute([$this->supplierId]);
        $this->documentId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $this->documentId);
        $stmt = $pdo->prepare('SELECT bank_transaction_id FROM payment_matches WHERE supplier_id=? AND purchase_invoice_id=?');
        $stmt->execute([$this->supplierId, $this->documentId]);
        $this->bankId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $this->bankId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
    }

    public static function cases(): iterable
    {
        foreach (['full', 'before', 'partial', 'header_only', 'unposted', 'wrong_posted_amount', 'ambiguous_member', 'wrong_tuple', 'wrong_account', 'missing_foreign', 'reversed'] as $case) yield $case => [$case];
    }

    #[DataProvider('cases')]
    public function testClosedPositionRequiresExactCurrentSettlementProof(string $case): void
    {
        $pdo = $this->db->pdo();
        if ($case === 'partial') $pdo->prepare('UPDATE payment_matches SET amount=amount-1 WHERE supplier_id=? AND purchase_invoice_id=?')->execute([$this->supplierId, $this->documentId]);
        if ($case === 'header_only') $pdo->prepare('DELETE FROM payment_matches WHERE supplier_id=? AND purchase_invoice_id=?')->execute([$this->supplierId, $this->documentId]);
        if ($case === 'unposted') $pdo->prepare("UPDATE journal_entries SET posted_at=NULL WHERE supplier_id=? AND source_type='bank' AND source_id=?")->execute([$this->supplierId, $this->bankId]);
        if ($case === 'wrong_posted_amount') $pdo->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id SET l.amount=l.amount-1 WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=?")->execute([$this->supplierId, $this->bankId]);
        if ($case === 'wrong_tuple') $pdo->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id SET l.cost_center='OTHER' WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=?")->execute([$this->supplierId, $this->bankId]);
        if ($case === 'wrong_account') $pdo->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id JOIN chart_of_accounts old ON old.id=l.account_id JOIN chart_of_accounts replacement ON replacement.supplier_id=e.supplier_id AND replacement.account_code='395' SET l.account_id=replacement.id WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=? AND old.account_code='321.000'")->execute([$this->supplierId, $this->bankId]);
        if ($case === 'missing_foreign') $pdo->prepare("UPDATE journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id SET l.amount_foreign=NULL WHERE e.supplier_id=? AND e.source_type='purchase_invoice' AND e.source_id=? AND l.currency_code='EUR'")->execute([$this->supplierId, $this->documentId]);
        if ($case === 'reversed') {
            $container = Bootstrap::buildContainer();
            $entry = $container->get(\MyInvoice\Repository\JournalEntryRepository::class)->findBySource($this->supplierId, 'bank', $this->bankId);
            $container->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplierId, (int) $entry['id'], ['entry_date' => '2025-07-21']);
            foreach (array_filter((new ClosingRepository($this->db))->fxItemsWithSettlement($this->supplierId, '2025-07-20'), fn (array $row): bool => $row['item']['doc_type'] === 'purchase_invoice' && $row['item']['doc_id'] === $this->documentId) as $row) self::assertTrue($row['evidence']['complete']);
        }
        if ($case === 'ambiguous_member') {
            $pdo->prepare('INSERT INTO payment_matches (supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type) SELECT supplier_id,?,id,1,"manual" FROM purchase_invoices WHERE supplier_id=? AND id<>? ORDER BY id LIMIT 1')->execute([$this->bankId, $this->supplierId, $this->documentId]);
        }
        $repo = new ClosingRepository($this->db);
        $asOf = match ($case) { 'before' => '2025-07-19', 'reversed' => '2025-07-21', default => '2025-07-20' };
        $rows = array_values(array_filter($repo->fxItemsWithSettlement($this->supplierId, $asOf, true), fn (array $row): bool => $row['item']['doc_type'] === 'purchase_invoice' && $row['item']['doc_id'] === $this->documentId));
        self::assertCount(2, $rows);
        self::assertSame(['EUR', 'EUR'], array_column(array_column($rows, 'item'), 'currency_code'));
        foreach ($rows as $row) {
            $evidence = $row['evidence'];
            self::assertSame('CZK', $evidence['document_currency']);
            if ($case === 'full') {
                self::assertTrue($evidence['complete'], json_encode($evidence['findings']));
                self::assertSame('0.00', $evidence['remaining_foreign']);
                self::assertSame('0.00', $evidence['booked_czk']);
            } else {
                self::assertFalse($evidence['complete']);
                self::assertContains('document_currency_conflict', $evidence['findings']);
            }
        }
        self::assertTrue($pdo->inTransaction());
    }
}
