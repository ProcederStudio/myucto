<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Opakovaný převod měsíce, při kterém MariaDB odvolá rušení vstupu z dřívější dávky
 * deadlockem (1213). Odvolaná je celá transakce převodu i s novou dávkou docházky;
 * zbytek převodu nesmí běžet v autocommitu a protokol nesmí tvrdit, že vstup už
 * vstoupil do mzdového běhu. Deadlock vyvolá trigger se SIGNAL `40001`/1213 jen
 * v session testu a jen u vstupu, který převod ruší.
 */
#[Group('integration')]
final class PohodaPayrollRepeatedMonthAbortTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TRIGGER = 'trg_test_abort_superseded_input';

    private Connection $db;
    private PohodaPayrollImporter $importer;
    private int $userId = 0;
    private int $sourceSupplierId = 0;
    private string $tmp = '';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(PohodaPayrollImporter::class);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $this->sourceSupplierId, 'Testovací DB nemá výchozí firmu.');
        self::assertGreaterThan(0, $this->userId, 'Testovací DB nemá uživatele.');
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_payroll_abort_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->exec('SET @payroll_import_abort = NULL');
        $pdo->exec('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        $pdo->exec(
            'CREATE TRIGGER ' . self::TRIGGER . " BEFORE UPDATE ON payroll_inputs FOR EACH ROW
             BEGIN
                 IF @payroll_import_abort > 0 AND OLD.status = 'approved' AND NEW.status <> 'approved'
                    AND NEW.period_start = '2026-02-01'
                    AND EXISTS (SELECT 1 FROM payroll_component_definitions c
                                 WHERE c.id = NEW.component_id AND c.code LIKE 'PAM\\_O01%') THEN
                     SET @payroll_import_abort = @payroll_import_abort - 1;
                     SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213,
                         MESSAGE_TEXT = 'Deadlock found when trying to get lock; try restarting transaction';
                 END IF;
             END"
        );
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('SET @payroll_import_abort = NULL');
            $pdo->exec('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testDeadlockWhileCancellingSupersededInputReachesCaller(): void
    {
        $supplierId = $this->payrollSupplier();
        $file = SyntheticPohodaPayroll::write($this->tmp);
        $first = $this->importer->run($supplierId, $this->userId, $file, SyntheticPohodaPayroll::YEAR, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
        self::assertFalse($first->hasErrors());

        $changedDir = $this->tmp . '/changed/' . basename(dirname($file));
        mkdir($changedDir, 0755, true);
        $changed = $changedDir . '/' . basename($file);
        $xml = (string) file_get_contents($file);
        $without = preg_replace('~<MZslozky><ID>112</ID>.*?</MZslozky>~s', '', $xml, 1);
        self::assertNotSame($xml, $without);
        file_put_contents($changed, $without);

        $pdo = $this->db->pdo();
        $pdo->exec('SET @payroll_import_abort = 1');
        try {
            $again = $this->importer->run($supplierId, $this->userId, $changed, SyntheticPohodaPayroll::YEAR, false, null, null, null, false, true, PohodaPayrollImporter::START_KEEP);
            self::fail('Odvolané rušení vstupu musí dostat vlastník transakce, ne protokol: '
                . json_encode($again->toArray()['steps'], JSON_UNESCAPED_UNICODE));
        } catch (\PDOException $e) {
            self::assertSame(1213, (int) $e->errorInfo[1]);
        }
        self::assertSame(0, (int) $pdo->query('SELECT @payroll_import_abort')->fetchColumn(), 'Odvolání se musí opravdu vyvolat.');
        self::assertFalse($pdo->inTransaction());
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Po odvolání nesmí v autocommitu zůstat nic z převodu.');
    }

    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$supplierId, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:pohoda-payroll")',
        )->execute([$supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$supplierId, $officeId]);
        return $supplierId;
    }
}
