<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Uploads;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Premier\PremierUploads;
use MyInvoice\Service\Migration\Shared\ChunkedUploadMessages;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Společné úložiště nahraných souborů převodů: konfigurace zdroje (podadresář, soubor
 * části, rozbalená data, výjimka, texty) se promítne do cest i chyb, jádro je jedno.
 */
final class ChunkedUploadStoreTest extends TestCase
{
    private const SUPPLIER = 2147480031;
    private const SUPPLIER_OTHER = 2147480032;

    private ChunkedUploadStore $store;

    protected function setUp(): void
    {
        $this->store = new ChunkedUploadStore(
            'shared-store-test',
            'file.part',
            'data',
            static fn (string $code, string $message, array $context, int $status): \RuntimeException
                => new TestUploadException($code, $message, $context, $status),
            new ChunkedUploadMessages('nf', 'meta-nf', 'up-nf', 'not-up', 'offset', 'storage', 'too-large', 'exceeds', 'write', 'empty'),
            ['extra/*.txt'],
        );
    }

    protected function tearDown(): void
    {
        foreach ([self::SUPPLIER, self::SUPPLIER_OTHER] as $supplierId) {
            $base = $this->store->base($supplierId);
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($base);
        }
    }

    public function testInvalidTokenFailsWithSourceExceptionAndText(): void
    {
        $e = $this->error(fn () => $this->store->dir(self::SUPPLIER, 'ABC'));
        self::assertSame(['upload_not_found', 'nf', 404], [$e->errorCode, $e->getMessage(), $e->getCode()]);
        $e = $this->error(fn () => $this->store->dir(0, str_repeat('a', 16)));
        self::assertSame('upload_not_found', $e->errorCode);

        // Úklid s neplatným tokenem nic nedělá a nehází.
        $this->store->purge(self::SUPPLIER, '../..');
        $this->store->discardData(self::SUPPLIER, '../..');
        self::assertFalse($this->store->isValid(self::SUPPLIER, '../..'));
    }

    public function testMetaAndStateRoundTripUnderConfiguredPaths(): void
    {
        $token = $this->store->newToken();
        self::assertMatchesRegularExpression(ChunkedUploadStore::TOKEN_PATTERN, $token);
        $dir = $this->store->dir(self::SUPPLIER, $token);
        mkdir($dir, 0755, true);

        self::assertStringEndsWith('shared-store-test/' . self::SUPPLIER . '/' . $token, str_replace('\\', '/', $dir));
        self::assertSame($dir . '/data', $this->store->dataDir(self::SUPPLIER, $token));
        self::assertSame($dir . '/file.part', $this->store->partPath(self::SUPPLIER, $token));

        self::assertNull($this->store->state(self::SUPPLIER, $token));
        self::assertFalse($this->store->hasMeta(self::SUPPLIER, $token));
        $e = $this->error(fn () => $this->store->meta(self::SUPPLIER, $token));
        self::assertSame(['meta-nf', 404], [$e->getMessage(), $e->getCode()]);

        $this->store->writeState(self::SUPPLIER, $token, ['status' => 'uploading', 'size' => 3]);
        self::assertSame(['status' => 'uploading', 'size' => 3, 'received' => 2], $this->store->updateState(self::SUPPLIER, $token, ['received' => 2]));
        $this->store->writeMeta(self::SUPPLIER, $token, ['name' => 'Účetní firma']);
        self::assertSame(['name' => 'Účetní firma'], $this->store->meta(self::SUPPLIER, $token));
        self::assertSame([], glob($dir . '/*.tmp'), 'Dočasný soubor zápisu nezůstane.');
    }

    public function testAppendChunkUsesConfiguredTexts(): void
    {
        $token = str_repeat('c', 16);
        mkdir($this->store->dir(self::SUPPLIER, $token), 0755, true);
        $streams = new StreamFactory();

        $e = $this->error(fn () => $this->store->appendChunk(self::SUPPLIER, $token, 0, $streams->createStream('x'), 8));
        self::assertSame(['upload_not_found', 'up-nf', 404], [$e->errorCode, $e->getMessage(), $e->getCode()]);

        $this->store->writeState(self::SUPPLIER, $token, ['status' => ChunkedUploadStore::STATUS_UPLOADING, 'size' => 4]);
        $e = $this->error(fn () => $this->store->appendChunk(self::SUPPLIER, $token, 0, $streams->createStream(''), 8));
        self::assertSame(['chunk_empty', 'empty', 400], [$e->errorCode, $e->getMessage(), $e->getCode()]);
        self::assertSame(4, $this->store->appendChunk(self::SUPPLIER, $token, 0, $streams->createStream('abcd'), 8));

        $this->store->updateState(self::SUPPLIER, $token, ['status' => ChunkedUploadStore::STATUS_PROCESSING]);
        $e = $this->error(fn () => $this->store->appendChunk(self::SUPPLIER, $token, 4, $streams->createStream('e'), 8));
        self::assertSame(['upload_not_uploading', 'not-up', 409, 4], [$e->errorCode, $e->getMessage(), $e->getCode(), $e->context['received']]);
    }

    public function testConfiguredActivityFilesKeepUploadAlive(): void
    {
        $token = str_repeat('d', 16);
        $dir = $this->store->dir(self::SUPPLIER, $token);
        mkdir($dir . '/extra', 0755, true);
        file_put_contents($dir . '/meta.json', '{}');
        touch($dir . '/meta.json', time() - 8 * 86400);
        file_put_contents($dir . '/extra/note.txt', 'x');
        @touch($dir, time() - 8 * 86400);

        self::assertSame(0, $this->store->purgeStale(self::SUPPLIER));
        self::assertDirectoryExists($dir);

        touch($dir . '/extra/note.txt', time() - 8 * 86400);
        self::assertSame(1, $this->store->purgeStale(self::SUPPLIER));
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testDailyCleanupRemovesUploadsIdleForMoreThanDay(): void
    {
        $idle = $this->store->dir(self::SUPPLIER, str_repeat('1', 16));
        $recent = $this->store->dir(self::SUPPLIER, str_repeat('2', 16));
        foreach ([$idle => time() - 25 * 3600, $recent => time() - 23 * 3600] as $dir => $mtime) {
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/meta.json', '{}');
            touch($dir . '/meta.json', $mtime);
            @touch($dir, $mtime);
        }

        self::assertSame(1, $this->store->purgeStaleAll());
        self::assertDirectoryDoesNotExist($idle);
        self::assertDirectoryExists($recent);
    }

    public function testMakeRoomKeepsNewestIdleAndBusyUploads(): void
    {
        $dirs = [];
        foreach (['1', '2', '3'] as $i => $c) {
            $token = str_repeat($c, 16);
            $dirs[$c] = $this->store->dir(self::SUPPLIER, $token);
            mkdir($dirs[$c], 0755, true);
            file_put_contents($dirs[$c] . '/meta.json', '{}');
            touch($dirs[$c] . '/meta.json', time() - 3600 * (3 - $i));
        }
        $lock = $this->store->acquireJobLock(self::SUPPLIER, str_repeat('1', 16));
        self::assertNotNull($lock);

        // Max 3: nejstarší '1' drží job, z nečinných zůstane jen nejnovější '3'.
        self::assertTrue($this->store->makeRoom(self::SUPPLIER, 3));
        self::assertDirectoryExists($dirs['1']);
        self::assertDirectoryDoesNotExist($dirs['2']);
        self::assertDirectoryExists($dirs['3']);

        self::assertFalse($this->store->makeRoom(self::SUPPLIER, 1), 'Všechna místa drží běžící job.');
        $this->store->releaseJobLock($lock);
    }

    public function testDiscardDataKeepsStateForTheWizard(): void
    {
        $token = str_repeat('e', 16);
        $dir = $this->store->dir(self::SUPPLIER, $token);
        mkdir($dir . '/data/sub', 0755, true);
        file_put_contents($dir . '/data/sub/a.xml', 'x');
        file_put_contents($dir . '/file.part', 'x');
        file_put_contents($dir . '/meta.json', '{}');
        $this->store->writeState(self::SUPPLIER, $token, ['status' => 'failed']);

        $this->store->discardData(self::SUPPLIER, $token);

        self::assertDirectoryDoesNotExist($dir . '/data');
        self::assertFileDoesNotExist($dir . '/file.part');
        self::assertFileDoesNotExist($dir . '/meta.json');
        self::assertSame(['status' => 'failed'], $this->store->state(self::SUPPLIER, $token));
    }

    public function testPurgeStaleAllCoversEveryCompanyOfTheSource(): void
    {
        foreach ([self::SUPPLIER, self::SUPPLIER_OTHER] as $supplierId) {
            $dir = $this->store->dir($supplierId, str_repeat('f', 16));
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/meta.json', '{}');
            touch($dir . '/meta.json', time() - 8 * 86400);
        }
        self::assertGreaterThanOrEqual(2, $this->store->purgeStaleAll());
        self::assertDirectoryDoesNotExist($this->store->dir(self::SUPPLIER_OTHER, str_repeat('f', 16)));
    }

    /** Fasády zdrojů zůstávají u svých cest, výjimek a textů. */
    public function testSourceFacadesKeepTheirConfiguration(): void
    {
        self::assertStringEndsWith('/agenda', str_replace('\\', '/', MoneyS3Uploads::agendaDir(self::SUPPLIER, str_repeat('a', 16))));
        self::assertStringEndsWith('/backup.lz.part', str_replace('\\', '/', MoneyS3Uploads::partPath(self::SUPPLIER, str_repeat('a', 16))));
        self::assertStringEndsWith('/export.zip.part', str_replace('\\', '/', PohodaUploads::partPath(self::SUPPLIER, str_repeat('a', 16))));
        self::assertStringEndsWith('/premier/' . self::SUPPLIER . '/' . str_repeat('a', 16) . '/backup', str_replace('\\', '/', PremierUploads::backupDir(self::SUPPLIER, str_repeat('a', 16))));

        try {
            MoneyS3Uploads::dir(self::SUPPLIER, 'x');
            self::fail('Neplatný token měl selhat.');
        } catch (MoneyS3Exception $e) {
            self::assertSame('Nahraná záloha nebyla nalezena.', $e->getMessage());
        }
        try {
            PohodaUploads::meta(self::SUPPLIER, str_repeat('9', 16));
            self::fail('Chybějící export měl selhat.');
        } catch (PohodaException $e) {
            self::assertSame(['Nahraný export nebyl nalezen (mohl být už uklizen).', 404], [$e->getMessage(), $e->getCode()]);
        }
    }

    private function error(callable $fn): TestUploadException
    {
        try {
            $fn();
        } catch (TestUploadException $e) {
            return $e;
        }
        self::fail('Volání mělo selhat.');
    }
}

final class TestUploadException extends \RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $context, int $status)
    {
        parent::__construct($message, $status);
    }
}
