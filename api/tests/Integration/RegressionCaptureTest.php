<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Config\Config;
use MyUctoRegression\Runner;
use MyUctoRegression\Safety;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionCaptureTest extends TestCase
{
    public function testEveryManifestKindCapturesRealServicesOnFullyMigratedSyntheticDatabase(): void
    {
        if (getenv('MYINVOICE_REGRESSION_SYNTHETIC_DB') !== '1') {
            self::markTestSkipped('Explicit opt-in creates and drops a fully migrated owned synthetic database.');
        }
        $root = dirname(__DIR__, 3);
        $config = Config::load($root);
        $host = (string) $config->get('db.host', '127.0.0.1');
        Safety::host($host, null);
        $name = 'myucto_regcapture_' . bin2hex(random_bytes(8)) . '_test';
        Safety::database($name, (string) $config->get('db.name'));
        $server = new \PDO('mysql:host=' . $host . ';port=' . (int) $config->get('db.port', 3306) . ';charset=utf8mb4', (string) $config->get('db.user'), (string) $config->get('db.pass', ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $workspace = sys_get_temp_dir() . '/regression-full-schema-' . bin2hex(random_bytes(8));
        mkdir($workspace, 0700);
        Safety::outside($workspace, [$root]);
        $env = getenv();
        foreach (['DATABASE_URL', 'MYSQL_URL', 'MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'REDIS_URL'] as $key) {
            unset($env[$key]);
        }
        $env['MYINVOICE_DB_NAME'] = $name;
        $env['MYINVOICE_DATA_DIR'] = $workspace . '/setup-runtime';
        $env['MYINVOICE_REDIS_ENABLED'] = 'false';
        $owned = false;
        try {
            $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $owned = true;
            self::runSetup([$root . '/api/bin/migrate.php', '--no-backfills', '--no-analyze'], $env, $workspace . '/migrate.log');
            self::runSetup([$root . '/api/bin/ci-seed.php'], $env, $workspace . '/ci-seed.log');
            self::runSetup([$root . '/api/tests/Fixtures/regression-capture-seed.php', $name], $env, $workspace . '/fixture.log');
            $output = $workspace . '/capture.json';
            ob_start();
            $exit = Runner::main(['regression', 'capture', '--code-root', $root, '--database', $name, '--as-of', '2025-12-31', '--execution-date', date('Y-m-d'), '--dataset-id', hash('sha256', 'synthetic-full-schema-v1'), '--output', $output]);
            $stdout = ob_get_clean();
            self::assertSame(0, $exit, 'Capture failed; local synthetic output: ' . $output . '; summary: ' . $stdout);
            $capture = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('ok', $capture['status']);
            $byKind = [];
            foreach ($capture['manifest'] as $id => $case) {
                self::assertSame('ok', $capture['cases'][$id]['status'], $case['kind']);
                $byKind[$case['kind']] = $capture['cases'][$id]['data'];
            }
            $expected = ['balance_sheet', 'income_statement', 'income_statement_function', 'trial_balance', 'general_ledger', 'dppo_preview', 'dppo_inputs', 'dppo_balance_due', 'dppo_data', 'vat_ledger', 'vat_return_ledger', 'vat_book', 'vat_return', 'control_statement', 'recapitulative_statement'];
            sort($expected);
            $actual = array_keys($byKind);
            sort($actual);
            self::assertSame($expected, $actual);
            self::assertSame(30000.15, $byKind['balance_sheet']['checks']['profit_current']);
            self::assertSame(30000.15, $byKind['income_statement']['checks']['profit_current']);
            self::assertSame(30000.15, $byKind['dppo_data']['vh']);
            self::assertSame(1234.56, $byKind['dppo_inputs']['inputs']['tax_paid_advances']);
            self::assertSame(5065.0, $byKind['dppo_balance_due']['balance_due']);
            self::assertSame((int) $byKind['dppo_inputs']['inputs']['bank_account_id'], (int) $byKind['dppo_data']['bank_account']['id']);
            foreach ($expected as $kind) {
                self::assertTrue(self::hasNonzeroAmount($byKind[$kind]), $kind . ' must contain an actual nonzero financial value.');
            }
            $originalData = $workspace . '/original-instance';
            mkdir($originalData, 0700);
            file_put_contents($originalData . '/cfg.local.php', '<?php return ' . var_export(['db' => ['name' => $name]], true) . ';');
            $originalEnvironment = [];
            foreach (['MYINVOICE_DATA_DIR', 'MYINVOICE_DB_NAME', 'MYSQL_DATABASE', 'DATABASE_URL', 'MYSQL_URL'] as $key) {
                $originalEnvironment[$key] = getenv($key);
                putenv($key);
            }
            try {
                putenv('MYINVOICE_DATA_DIR=' . $originalData);
                $guarded = Runner::child([
                    'code_root' => $root, 'database' => $name, 'as_of' => '2025-12-31',
                    'execution_date' => date('Y-m-d'), 'docker_host' => null, 'action' => 'preflight',
                ], $workspace, 'original-working-database');
                self::assertSame('error', $guarded['status']);
                self::assertSame('test_database_required', $guarded['error']);
            } finally {
                foreach ($originalEnvironment as $key => $value) {
                    putenv($value === false ? $key : $key . '=' . $value);
                }
            }
        } finally {
            if ($owned) {
                Safety::database($name, (string) $config->get('db.name'));
                $server->exec('DROP DATABASE `' . $name . '`');
            }
        }
    }

    private static function runSetup(array $arguments, array $environment, string $log): void
    {
        $process = proc_open(array_merge([PHP_BINARY], $arguments), [['pipe', 'r'], ['file', $log, 'w'], ['file', $log, 'a']], $pipes, null, $environment, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        self::assertSame(0, proc_close($process), 'Synthetic setup failed; private log: ' . $log);
    }

    private static function hasNonzeroAmount(mixed $value, string $key = ''): bool
    {
        if ($key === 'xml' && is_string($value)) {
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($value, LIBXML_NONET));
            foreach ((new \DOMXPath($document))->query('//@*') as $attribute) {
                if (preg_match('/^(zakl_dane|dan[123]|pln_hodnota|obrat)/', $attribute->nodeName) && is_numeric($attribute->nodeValue) && (float) $attribute->nodeValue !== 0.0) {
                    return true;
                }
            }
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $child => $item) {
                if (self::hasNonzeroAmount($item, (string) $child)) {
                    return true;
                }
            }
            return false;
        }
        return !preg_match('/rate|percent|count|_id$/i', $key)
            && preg_match('/amount|balance_due|base|vat|tax|profit|total|turnover|^vh$|^dan$|^md$|^d$|^net$|^gross$|^ps_|^ks_|zaklad|dan[0-9_]/i', $key) === 1
            && is_numeric($value) && (float) $value !== 0.0;
    }
}
