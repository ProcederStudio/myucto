<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxUploads;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;

final class StereoNxUploadsTest extends TestCase
{
    private string $root;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('MYINVOICE_DATA_DIR');
        $this->root = sys_get_temp_dir() . '/stereo-nx-upload-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        if ($this->previous === false) putenv('MYINVOICE_DATA_DIR');
        else putenv('MYINVOICE_DATA_DIR=' . $this->previous);
        if (!is_dir($this->root)) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) ($file->isDir() && !$file->isLink()) ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testChunkCannotOverwriteOrExceedDeclaredLength(): void
    {
        $token = str_repeat('a', 32);
        $this->makeUpload($token, 6);
        $streams = new StreamFactory();
        self::assertSame(3, StereoNxUploads::append(123, $token, 0, $streams->createStream('abc')));
        $this->assertCode('chunk_offset_mismatch', fn () => StereoNxUploads::append(123, $token, 0, $streams->createStream('abc')));
        $this->assertCode('chunk_too_large', fn () => StereoNxUploads::append(123, $token, 3, $streams->createStream('defg')));
        self::assertSame('abc', file_get_contents(StereoNxUploads::archive(123, $token)));
        self::assertSame(6, StereoNxUploads::append(123, $token, 3, $streams->createStream('def')));
    }

    public function testStaleFilesAreRemovedAndThreeActiveUploadsBoundTheTenant(): void
    {
        $stale = str_repeat('a', 32);
        $this->makeUpload($stale, 6, time() - 8 * 86400);
        foreach (['b', 'c', 'd'] as $digit) $this->makeUpload(str_repeat($digit, 32), 6);
        self::assertFalse(StereoNxUploads::prepareRoom(123));
        self::assertDirectoryDoesNotExist(StereoNxUploads::dir(123, $stale));
        StereoNxUploads::delete(123, str_repeat('b', 32));
        self::assertTrue(StereoNxUploads::prepareRoom(123));
    }

    public function testDailyCleanupRemovesIdleBackupsOfAllTenants(): void
    {
        $idle = str_repeat('a', 32);
        $recent = str_repeat('b', 32);
        $this->makeUpload($idle, 6);
        $this->makeUpload($recent, 6);
        file_put_contents(StereoNxUploads::archive(123, $idle), 'ABCDEF');
        foreach (['state.json', 'backup.zip'] as $name) touch(StereoNxUploads::dir(123, $idle) . '/' . $name, time() - 25 * 3600);

        self::assertSame(1, StereoNxUploads::purgeStaleAll());
        self::assertDirectoryDoesNotExist(StereoNxUploads::dir(123, $idle));
        self::assertDirectoryExists(StereoNxUploads::dir(123, $recent));
    }

    public function testInvalidTokenCannotEscapeStorage(): void
    {
        $this->assertCode('upload_not_found', fn () => StereoNxUploads::dir(123, '../other'));
    }

    public function testListingContainsOnlyOwnTenantUploadsWithoutRemovingStaleFiles(): void
    {
        $ownReady = str_repeat('a', 32);
        $ownPartial = str_repeat('b', 32);
        $otherUser = str_repeat('c', 32);
        $old = time() - 8 * 86400;
        $this->makeUpload($ownReady, 6, $old);
        file_put_contents(StereoNxUploads::archive(123, $ownReady), 'ABCDEF');
        StereoNxUploads::save(123, $ownReady, [
            'size' => 6, 'received' => 6, 'status' => 'ready',
            'file_name' => 'backup.zip', 'uploaded_by' => 11, 'created_at' => $old,
        ]);
        $this->makeUpload($ownPartial, 10);
        StereoNxUploads::save(123, $ownPartial, [
            'size' => 10, 'received' => 4, 'status' => 'uploading',
            'file_name' => 'new.zip', 'uploaded_by' => 11, 'created_at' => time(),
        ]);
        $this->makeUpload($otherUser, 10);
        StereoNxUploads::save(123, $otherUser, [
            'size' => 10, 'received' => 0, 'status' => 'uploading',
            'file_name' => 'private.zip', 'uploaded_by' => 12, 'created_at' => time(),
        ]);

        $uploads = StereoNxUploads::listForUser(123, 11);
        self::assertCount(2, $uploads);
        self::assertSame($ownPartial, $uploads[0]['token']);
        self::assertSame(['token' => $ownReady, 'filename' => 'backup.zip', 'size' => 6,
            'received' => 6, 'complete' => true, 'created_at' => $old], $uploads[1]);
        self::assertDirectoryExists(StereoNxUploads::dir(123, $ownReady));
        self::assertSame([], StereoNxUploads::listForUser(124, 11));
        self::assertCount(1, StereoNxUploads::listForUser(123, 12));
    }

    public function testListingSkipsCorruptStateAndSymlinkedUploadDirectory(): void
    {
        $token = str_repeat('a', 32);
        $this->makeUpload($token, 6);
        file_put_contents(StereoNxUploads::dir(123, $token) . '/state.json', '{');
        $outside = $this->root . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/state.json', json_encode([
            'size' => 6, 'received' => 6, 'status' => 'ready',
            'file_name' => 'outside.zip', 'uploaded_by' => 11, 'created_at' => time(),
        ], JSON_THROW_ON_ERROR));
        @symlink($outside, StereoNxUploads::dir(123, str_repeat('b', 32)));
        self::assertSame([], StereoNxUploads::listForUser(123, 11));
    }

    private function makeUpload(string $token, int $size, ?int $createdAt = null): void
    {
        $dir = StereoNxUploads::dir(123, $token);
        mkdir($dir, 0700, true);
        StereoNxUploads::save(123, $token, ['size' => $size, 'received' => 0, 'status' => 'uploading', 'created_at' => $createdAt ?? time()]);
    }

    private function assertCode(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected Stereo NX upload error.');
        } catch (StereoNxException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
