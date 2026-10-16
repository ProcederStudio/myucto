<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\FileImportJobService;
use PHPUnit\Framework\TestCase;

/**
 * Denní úklid souborů odložených pro import na pozadí: nedokončená dávka nebo job,
 * jehož worker nedoběhl, nesmí ležet v úložišti déle než den. Reporty jobů zůstávají.
 */
final class FileImportStagingCleanupTest extends TestCase
{
    private string $root;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('MYINVOICE_DATA_DIR');
        $this->root = sys_get_temp_dir() . '/file-import-staging-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        if ($this->previous === false) putenv('MYINVOICE_DATA_DIR');
        else putenv('MYINVOICE_DATA_DIR=' . $this->previous);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testRemovesStagingIdleForMoreThanDayAndKeepsReports(): void
    {
        $idle = $this->stage(7, str_repeat('a', 16), time() - 25 * 3600);
        $otherTenant = $this->stage(8, str_repeat('b', 16), time() - 30 * 86400);
        $recent = $this->stage(7, str_repeat('c', 16), time() - 23 * 3600);
        $reports = FileImportJobService::stagingDir(7, 'reports');
        mkdir($reports, 0755, true);
        file_put_contents($reports . '/1.json', '{}');
        touch($reports . '/1.json', time() - 30 * 86400);

        self::assertSame(2, FileImportJobService::purgeStaleStagingAll());
        self::assertDirectoryDoesNotExist($idle);
        self::assertDirectoryDoesNotExist($otherTenant);
        self::assertDirectoryExists($recent);
        self::assertFileExists($reports . '/1.json');
    }

    private function stage(int $supplierId, string $token, int $mtime): string
    {
        $dir = FileImportJobService::stagingDir($supplierId, $token);
        mkdir($dir, 0755, true);
        foreach (['0.bin', 'manifest.json'] as $name) {
            file_put_contents($dir . '/' . $name, 'x');
            touch($dir . '/' . $name, $mtime);
        }
        return $dir;
    }
}
