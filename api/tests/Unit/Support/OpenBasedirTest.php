<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\OpenBasedir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenBasedirTest extends TestCase
{
    /** @return iterable<string,array{0:string,1:string,2:bool}> */
    public static function cases(): iterable
    {
        $sep = PATH_SEPARATOR;
        yield 'bez omezení' => ['/usr/bin/php', '', true];
        yield 'mimo povolený strom' => ['/usr/bin/php', "/var/www/clients/web1/{$sep}/tmp/", false];
        yield 'dockerenv mimo strom' => ['/.dockerenv', '/var/www/clients/web1/', false];
        yield 'uvnitř stromu' => ['/var/www/clients/web1/web/cfg.php', "/tmp/{$sep}/var/www/clients/web1/", true];
        yield 'prefix bez lomítka jako v PHP' => ['/var/www2/x', '/var/www', true];
        yield 'návrat nad strom' => ['/var/www/clients/web1/../web2/cfg.php', '/var/www/clients/web1/', false];
    }

    #[DataProvider('cases')]
    public function testAllowsMatchesOpenBasedirPrefixes(string $path, string $openBasedir, bool $expected): void
    {
        self::assertSame($expected, OpenBasedir::allows($path, $openBasedir));
    }

    /**
     * Skutečný běh pod open_basedir: kontrolní `is_file()` varování vyhodí
     * (prostředí chybu reprodukuje), sdílená pomocná funkce ani detekce
     * kontejneru pro cron a aktualizace ne.
     */
    public function testProbesOutsideOpenBasedirDoNotWarn(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open není k dispozici.');
        }
        $apiDir = dirname(__DIR__, 3);
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'obd-' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        $script = $tmpDir . DIRECTORY_SEPARATOR . 'probe.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            $warnings = [];
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            });
            $control = is_file('/.dockerenv');
            $controlWarnings = count($warnings);
            $warnings = [];
            MyInvoice\Support\OpenBasedir::isFile('/usr/bin/php');
            MyInvoice\Support\OpenBasedir::isExecutableFile('/usr/local/bin/php');
            MyInvoice\Service\Cron\DockerCrontabGenerator::runningInContainer();
            echo json_encode(['control' => $controlWarnings, 'warnings' => $warnings]);
            PHP);
        try {
            $command = [
                PHP_BINARY, '-n',
                '-d', 'open_basedir=' . $apiDir . PATH_SEPARATOR . $tmpDir,
                $script, $apiDir,
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            @unlink($script);
            @rmdir($tmpDir);
        }

        $result = json_decode($stdout, true);
        self::assertIsArray($result, $stdout . $stderr);
        self::assertGreaterThan(0, $result['control'], 'Kontrolní is_file() mimo open_basedir má varovat.');
        self::assertSame([], $result['warnings']);
    }
}
