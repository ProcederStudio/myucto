<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\MyInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use MyInvoice\Service\Migration\MyInvoice\MyInvoiceBackupConverter;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use MyInvoice\Service\Migration\Myucto\MyuctoImportFiles;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[Group('integration')]
final class MyInvoiceBackupImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testConvertedBackupImportsThroughNativePathAndCanBeRepeated(): void
    {
        $db = Bootstrap::buildContainer()->get(Connection::class);
        $pdo = $db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $base = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $actor = (int) $pdo->query('SELECT id FROM users WHERE is_active = 1 LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $base);
        self::assertGreaterThan(0, $actor);
        $target = $this->createIsolatedSupplier($pdo, $base);
        $root = sys_get_temp_dir() . '/myinvoice-native-test-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $oldDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $root);
        try {
            $this->setVatPayerAt($pdo, $target, '1900-01-01', true);
            $pdo->prepare("UPDATE supplier SET ic = '00000000', accounting_mode = 'tax_evidence', taxpayer_type = 'fo', vat_period = 'monthly' WHERE id = ?")->execute([$target]);
            $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default) VALUES (?, 'CZK', 'Test', 'Kč', 'Test', 'Test', 1)")->execute([$target]);
            $currency = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $target]);
            $country = $pdo->query("SELECT * FROM countries WHERE iso2 = 'CZ'")->fetch(PDO::FETCH_ASSOC);
            $vat = $pdo->query("SELECT * FROM vat_rates WHERE rate_percent = 21 AND country = 'CZ' AND is_reverse_charge = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($country);
            self::assertIsArray($vat);
            $original = "%PDF-synthetic-original\n";
            $tables = [
                'supplier' => [['id' => 1001, 'company_name' => 'Synthetic source', 'ic' => '00000000',
                    'country_id' => $country['id'], 'default_currency_id' => 2001, 'is_vat_payer' => 1,
                    'is_identified' => 0, 'taxpayer_type' => 'fo', 'vat_period' => 'monthly']],
                'countries' => [$country],
                'vat_rates' => [$vat],
                'currencies' => [['id' => 2001, 'supplier_id' => 1001, 'code' => 'CZK', 'label' => 'Test',
                    'symbol' => 'Kč', 'name_cs' => 'Test', 'name_en' => 'Test', 'decimals' => 2]],
                'clients' => [['id' => 3001, 'supplier_id' => 1001, 'company_name' => 'Synthetic partner',
                    'street' => 'Test 1', 'city' => 'Test', 'zip' => '11000', 'country_id' => $country['id'],
                    'main_email' => 'partner@example.test', 'currency_default_id' => 2001]],
                'recurring_invoice_templates' => [['id' => 4001, 'supplier_id' => 1001, 'client_id' => 3001,
                    'currency_id' => 2001, 'name' => 'Synthetic recurring', 'frequency' => 'monthly',
                    'anchor_date' => '2090-01-01', 'next_run_date' => '2090-02-01', 'created_by' => 999999,
                    'prices_include_vat' => 1, 'auto_issue' => 1, 'auto_send_email' => 1]],
                'recurring_invoice_template_items' => [['id' => 4101, 'template_id' => 4001,
                    'description' => 'Synthetic recurring', 'unit_price_without_vat' => '121.00', 'vat_rate_id' => $vat['id']]],
                'invoices' => [['id' => 5001, 'supplier_id' => 1001, 'client_id' => 3001, 'currency_id' => 2001,
                    'invoice_type' => 'invoice', 'varsymbol' => 'SYNTHETIC-2090', 'issue_date' => '2090-01-01',
                    'tax_date' => '2090-01-01', 'due_date' => '2090-01-15', 'created_by' => 999999,
                    'recurring_template_id' => 4001, 'prices_include_vat' => 1, 'status' => 'issued',
                    'total_without_vat' => '100.00', 'total_vat' => '21.00', 'total_with_vat' => '121.00',
                    'supplier_snapshot' => '{"id":1001,"company_name":"Synthetic source"}']],
                'work_reports' => [['id' => 5201, 'invoice_id' => 5001, 'project_id' => null, 'title' => 'Synthetic report']],
                'invoice_items' => [['id' => 5101, 'invoice_id' => 5001, 'description' => 'Synthetic service',
                    'unit_price_without_vat' => '121.00', 'vat_rate_id' => $vat['id'], 'vat_rate_snapshot' => '21.00',
                    'total_without_vat' => '100.00', 'total_vat' => '21.00', 'total_with_vat' => '121.00']],
                'purchase_invoices' => [['id' => 6001, 'supplier_id' => 1001, 'vendor_id' => 3001,
                    'currency_id' => 2001, 'vendor_invoice_number' => 'SYNTHETIC-PURCHASE-2090',
                    'vendor_snapshot' => '{"company_name":"Synthetic partner"}', 'issue_date' => '2090-01-05',
                    'tax_date' => '2090-01-05', 'due_date' => '2090-01-15', 'received_at' => '2090-02-01',
                    'received_at_source' => 'manual', 'created_by' => 999999, 'status' => 'received',
                    'prices_include_vat' => 1, 'total_without_vat' => '100.00', 'total_vat' => '21.00',
                    'total_with_vat' => '121.00', 'pdf_path' => 'sup-1001/original.pdf', 'pdf_hash' => hash('sha256', $original)]],
                'purchase_invoice_items' => [['id' => 6101, 'purchase_invoice_id' => 6001, 'description' => 'Synthetic cost',
                    'unit_price_without_vat' => '121.00', 'vat_rate_id' => $vat['id'], 'vat_rate_snapshot' => '21.00',
                    'total_without_vat' => '100.00', 'total_vat' => '21.00', 'total_with_vat' => '121.00']],
            ];
            $this->zip($root . '/db.zip', ['backup.sql' => $this->dump($tables)]);
            $this->zip($root . '/files.zip', ['storage/purchase-invoices/sup-1001/original.pdf' => $original]);
            (new MyInvoiceBackupConverter())->convert($root . '/db.zip', [$root . '/files.zip'], $root . '/out.zip',
                1001, 'tax_evidence', [['effective_from' => '1900-01-01', 'is_vat_payer' => 1,
                    'is_identified' => 0, 'annual_deduction_percent' => null]]);
            $package = (new MyuctoExportReader())->read($root . '/out.zip');
            $importer = new MyuctoImporter($db, new TenantScopeResolver($db),
                new MyuctoImportFiles(new Config(['purchase_invoice' => ['archive_storage' => $root . '/purchase-invoices']])));
            $before = $pdo->query('SELECT * FROM supplier WHERE id = ' . $target)->fetch(PDO::FETCH_ASSOC);
            $config = new Config(['app' => ['secret_encryption_key' => base64_encode(random_bytes(32))]]);
            $workflow = new \MyInvoice\Service\Migration\Myucto\MyuctoImportWorkflow(new MyuctoExportReader(), $importer, $config);
            $bridge = new \MyInvoice\Service\Migration\MyInvoice\MyInvoiceBackupImportBridge($db, $config, $workflow, new MyInvoiceBackupConverter());
            $jobs = new \MyInvoice\Repository\ImportJobRepository($db);
            $service = new \MyInvoice\Service\Migration\Myucto\MyuctoImportJobService(
                $jobs, $workflow, new \MyInvoice\Service\Auth\SecretEncryption($config),
                new \MyInvoice\Service\Migration\Shared\MigrationCompanyLock($db),
                new \MyInvoice\Service\ActivityLogger($db), new \Psr\Log\NullLogger(), $bridge,
            );
            $tokens = [];
            foreach (['db.zip', 'files.zip'] as $file) {
                $uploaded = $workflow->init($target, $actor, $file, filesize($root . '/' . $file), true);
                $token = $uploaded['token'];
                $workflow->chunk($target, $actor, $token, 0, (new \Slim\Psr7\Factory\StreamFactory())->createStream(file_get_contents($root . '/' . $file)));
                $workflow->complete($target, $actor, $token);
                $tokens[] = $token;
            }
            foreach ([[$actor, false], [$actor + 100000, true]] as [$wrongActor, $confirmed]) {
                try {
                    $bridge->options($target, $wrongActor, $tokens[0], ['files' => [$tokens[1]], 'profile_confirmed' => $confirmed]);
                    self::fail('Unconfirmed profile or foreign upload must be rejected.');
                } catch (\MyInvoice\Service\Migration\Myucto\MyuctoImportException $e) {
                    self::assertContains($e->errorCode, ['invalid_options', 'upload_not_found']);
                }
            }
            $missingFiles = $service->start($target, $actor, $tokens[0], 'synthetic-myinvoice', null, false, false,
                ['files' => [], 'profile_confirmed' => true]);
            $service->run($missingFiles['job_id']);
            $failed = $service->status($target, $actor, $missingFiles['job_id']);
            self::assertSame('failed', $failed['status']);
            self::assertStringContainsString('Chybí originál', $failed['last_error']);
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE supplier_id = ' . $target)->fetchColumn());
            $started = $service->start($target, $actor, $tokens[0], 'synthetic-myinvoice', null, false, false,
                ['files' => [$tokens[1]], 'profile_confirmed' => true]);
            $service->run($started['job_id']);
            $checked = $service->status($target, $actor, $started['job_id']);
            self::assertSame('completed', $checked['status'], $checked['last_error'] ?? '');
            self::assertSame('myinvoice', $checked['format']);
            self::assertNotSame($tokens[0], $checked['token']);
            self::assertSame($checked['result']['token'], $checked['token']);
            $dry = $checked['result']['report'];
            self::assertSame(1, $dry['created']['invoices']);
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE supplier_id = ' . $target)->fetchColumn());
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM external_entity_map WHERE supplier_id = ' . $target)->fetchColumn());
            self::assertDirectoryDoesNotExist($root . '/purchase-invoices');
            $started = $service->start($target, $actor, $checked['token'], 'synthetic-myinvoice', null, true, true);
            $service->run($started['job_id']);
            $appliedRun = $service->status($target, $actor, $started['job_id']);
            self::assertSame('completed', $appliedRun['status'], $appliedRun['last_error'] ?? '');
            $applied = $appliedRun['result']['report'];
            self::assertSame(1, $applied['files']);
            $invoice = $pdo->query('SELECT * FROM invoices WHERE supplier_id = ' . $target)->fetch(PDO::FETCH_ASSOC);
            self::assertNotSame(5001, (int) $invoice['id']);
            self::assertSame('100.00', $invoice['total_without_vat']);
            self::assertSame('21.00', $invoice['total_vat']);
            self::assertSame('121.00', $invoice['total_with_vat']);
            self::assertSame(1, (int) $invoice['prices_include_vat']);
            self::assertSame($actor, (int) $invoice['created_by']);
            $workReport = $pdo->query('SELECT * FROM work_reports WHERE invoice_id = ' . (int) $invoice['id'])->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($workReport);
            self::assertNull($workReport['project_id']);
            self::assertSame($target, json_decode($invoice['supplier_snapshot'], true)['id']);
            $template = $pdo->query('SELECT * FROM recurring_invoice_templates WHERE supplier_id = ' . $target)->fetch(PDO::FETCH_ASSOC);
            self::assertSame('paused', $template['status']);
            self::assertSame(0, (int) $template['auto_issue']);
            self::assertSame(0, (int) $template['auto_send_email']);
            self::assertSame((int) $template['id'], (int) $invoice['recurring_template_id']);
            self::assertSame('121.000000', $pdo->query('SELECT unit_price_without_vat FROM recurring_invoice_template_items WHERE template_id = ' . (int) $template['id'])->fetchColumn());
            $purchase = $pdo->query('SELECT * FROM purchase_invoices WHERE supplier_id = ' . $target)->fetch(PDO::FETCH_ASSOC);
            self::assertSame($original, file_get_contents($root . '/purchase-invoices/' . $purchase['pdf_path']));
            $ledger = Bootstrap::buildContainer()->get(\MyInvoice\Service\Report\VatLedgerService::class);
            foreach ([['2090-01-01', '2090-01-31', 'sale'], ['2090-02-01', '2090-02-28', 'purchase']] as [$from, $to, $kind]) {
                $rows = $ledger->rows($target, $from, $to);
                self::assertCount(1, $rows);
                self::assertSame($kind, $rows[0]['source']);
                self::assertEquals(21, $rows[0]['vat_czk']);
            }
            self::assertSame($before, $pdo->query('SELECT * FROM supplier WHERE id = ' . $target)->fetch(PDO::FETCH_ASSOC));
            self::assertSame([], $importer->import($package, $target, $actor, 'synthetic-myinvoice', false)['created']);
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE supplier_id = ' . $target)->fetchColumn());
        } finally {
            foreach (['purchase_invoice_items' => ['purchase_invoice_id', 'purchase_invoices'],
                'invoice_items' => ['invoice_id', 'invoices'],
                'work_reports' => ['invoice_id', 'invoices'],
                'recurring_invoice_template_items' => ['template_id', 'recurring_invoice_templates']] as $table => [$key, $parent]) {
                $pdo->prepare("DELETE c FROM `$table` c JOIN `$parent` p ON p.id = c.`$key` WHERE p.supplier_id = ?")->execute([$target]);
            }
            $pdo->prepare('DELETE FROM import_jobs WHERE supplier_id = ?')->execute([$target]);
            foreach (['purchase_invoices', 'invoices', 'external_entity_map', 'recurring_invoice_templates', 'clients', 'supplier_vat_status_history'] as $table) {
                $pdo->prepare("DELETE FROM `$table` WHERE supplier_id = ?")->execute([$target]);
            }
            $pdo->prepare('UPDATE supplier SET default_currency_id = (SELECT default_currency_id FROM (SELECT default_currency_id FROM supplier WHERE id = ?) b) WHERE id = ?')->execute([$base, $target]);
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$target]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$target]);
            $this->removeDir($root);
            putenv($oldDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $oldDataDir);
        }
    }

    private function dump(array $tables): string
    {
        $sql = '';
        foreach ($tables as $table => $rows) {
            $sql .= 'CREATE TABLE `' . $table . "` (\n";
            $sql .= implode(",\n", array_map(static fn ($c) => '  `' . $c . '` text', array_keys($rows[0]))) . "\n);\n";
            foreach ($rows as $row) {
                $values = array_map(static fn ($v) => $v === null ? 'NULL' : "'" . str_replace(['\\', "'", "\0"], ['\\\\', "\\'", '\\0'], (string) $v) . "'", array_values($row));
                $sql .= 'INSERT INTO `' . $table . '` VALUES (' . implode(',', $values) . ");\n";
            }
        }
        return $sql;
    }

    private function zip(string $path, array $entries): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            self::assertTrue($zip->addFromString($name, $content));
        }
        self::assertTrue($zip->close());
    }

    private function removeDir(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            if (is_dir($child)) {
                $this->removeDir($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
