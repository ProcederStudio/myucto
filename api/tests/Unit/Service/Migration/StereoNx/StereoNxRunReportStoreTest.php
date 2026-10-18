<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxRunReportStore;
use MyInvoice\Service\Migration\StereoNx\StereoNxUploads;
use PHPUnit\Framework\TestCase;

final class StereoNxRunReportStoreTest extends TestCase
{
    private string $root;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('MYINVOICE_DATA_DIR');
        $this->root = sys_get_temp_dir() . '/stereo-run-report-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        if ($this->previous === false) putenv('MYINVOICE_DATA_DIR');
        else putenv('MYINVOICE_DATA_DIR=' . $this->previous);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) ($file->isDir() && !$file->isLink()) ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testStoredReportSurvivesUploadDeletionAndKeepsRawFailedResult(): void
    {
        $store = new StereoNxRunReportStore();
        $report = ['ok' => false, 'errors' => [['code' => 'synthetic_error', 'message' => 'Syntetická chyba']],
            'warnings' => [], 'counts' => ['assets_source' => 1]];
        $stored = $store->save(123, 45, $report);
        self::assertSame('import-jobs/123/reports/stereo-nx-45.json', $stored['path']);
        self::assertSame('application/json', $stored['mime']);
        $token = str_repeat('a', 32);
        mkdir(StereoNxUploads::dir(123, $token), 0700, true);
        StereoNxUploads::save(123, $token, ['uploaded_by' => 7, 'status' => 'ready']);
        StereoNxUploads::delete(123, $token);
        self::assertSame($report, $store->read(123, 45, $stored['path']));
        self::assertSame($stored['size'], filesize(RuntimePaths::storage($stored['path'])));
    }

    public function testOnlyCanonicalTenantAndJobPathCanBeRead(): void
    {
        $store = new StereoNxRunReportStore();
        $stored = $store->save(123, 45, ['ok' => true]);
        foreach ([null, '', '../reports/stereo-nx-45.json', 'import-jobs/123/reports/../reports/stereo-nx-45.json',
            'import-jobs/124/reports/stereo-nx-45.json', 'import-jobs/123/reports/stereo-nx-46.json'] as $path) {
            self::assertNull($store->read(123, 45, $path));
        }
        self::assertNull($store->read(124, 45, $stored['path']));
        self::assertNull($store->read(123, 46, $stored['path']));
        self::assertNull($store->read(123, 0, $stored['path']));
    }

    public function testMalformedJsonIsUnavailable(): void
    {
        $store = new StereoNxRunReportStore();
        $stored = $store->save(123, 45, ['ok' => true]);
        foreach (['{', 'null', '[]', '{"ok":"true"}'] as $json) {
            file_put_contents(RuntimePaths::storage($stored['path']), $json);
            self::assertNull($store->read(123, 45, $stored['path']));
        }
    }

    public function testReportSymlinkIsNeverReadOrOverwritten(): void
    {
        if (!function_exists('symlink')) self::markTestSkipped('Symbolic links are unavailable.');
        $store = new StereoNxRunReportStore();
        $stored = $store->save(123, 45, ['ok' => true]);
        $path = RuntimePaths::storage($stored['path']);
        $outside = $this->root . '/outside.json';
        file_put_contents($outside, '{"ok":false}');
        unlink($path);
        if (!@symlink($outside, $path)) self::markTestSkipped('Cannot create a symbolic link on this platform.');
        self::assertNull($store->read(123, 45, $stored['path']));
        try {
            $store->save(123, 45, ['ok' => true]);
            self::fail('A symlink must not be overwritten.');
        } catch (StereoNxException $e) {
            self::assertSame('storage_error', $e->errorCode);
        }
        self::assertSame('{"ok":false}', file_get_contents($outside));
    }

    public function testReportsDirectorySymlinkIsRejected(): void
    {
        if (!function_exists('symlink')) self::markTestSkipped('Symbolic links are unavailable.');
        mkdir(RuntimePaths::storage('import-jobs/123'), 0700, true);
        $outside = $this->root . '/outside';
        mkdir($outside, 0700);
        if (!@symlink($outside, RuntimePaths::storage('import-jobs/123/reports'))) self::markTestSkipped('Cannot create a symbolic link on this platform.');
        $store = new StereoNxRunReportStore();
        self::assertNull($store->read(123, 45, 'import-jobs/123/reports/stereo-nx-45.json'));
        $this->expectException(StereoNxException::class);
        $store->save(123, 45, ['ok' => true]);
    }
}
