<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Update;

use MyInvoice\Service\Update\FrontendAssetPruner;
use PHPUnit\Framework\TestCase;

/**
 * Úklid assetů předchozích vydání: zůstává aktuální i předchozí build a soubory mladší
 * než den, bundle bez seznamu souborů se nechá být.
 */
final class FrontendAssetPrunerTest extends TestCase
{
    private string $root;
    private string $dist;
    private string $state;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/myucto-asset-pruner-' . bin2hex(random_bytes(4));
        $this->dist = $this->root . '/dist';
        $this->state = $this->root . '/storage/frontend-builds.json';
        mkdir($this->dist . '/assets', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    public function testRemovesOldAssetsOfEarlierBuildsOnly(): void
    {
        $old = time() - 10 * 86400;
        $this->asset('index-aaa.js', $old);
        $this->asset('shared-111.js', $old);
        $this->build(['assets/index-aaa.js', 'assets/shared-111.js']);
        self::assertSame(0, $this->pruner()->prune()['removed']);

        $this->asset('index-bbb.js', $old);
        $this->build(['assets/index-bbb.js', 'assets/shared-111.js']);
        self::assertSame(0, $this->pruner()->prune()['removed'], 'Předchozí build zůstává pro otevřené záložky.');

        $this->asset('index-ccc.js', $old);
        $this->asset('fresh-999.js', time());
        $this->build(['assets/index-ccc.js', 'assets/shared-111.js']);
        $result = $this->pruner()->prune();

        self::assertSame(1, $result['removed']);
        self::assertFileDoesNotExist($this->dist . '/assets/index-aaa.js');
        self::assertFileExists($this->dist . '/assets/index-bbb.js');
        self::assertFileExists($this->dist . '/assets/index-ccc.js');
        self::assertFileExists($this->dist . '/assets/shared-111.js');
        self::assertFileExists($this->dist . '/assets/fresh-999.js', 'Soubor mladší než den zůstává.');    }

    public function testBundleWithoutFileListIsLeftAlone(): void
    {
        $this->asset('index-aaa.js', time() - 10 * 86400);
        $result = $this->pruner()->prune();
        self::assertSame(0, $result['removed']);
        self::assertNotNull($result['skipped']);

        file_put_contents($this->dist . '/build-files.json', '{"files":[]}');
        self::assertSame(0, $this->pruner()->prune()['removed']);
        self::assertFileExists($this->dist . '/assets/index-aaa.js');
    }

    private function pruner(): FrontendAssetPruner
    {
        return new FrontendAssetPruner($this->dist, $this->state);
    }

    private function asset(string $name, int $mtime): void
    {
        file_put_contents($this->dist . '/assets/' . $name, $name);
        touch($this->dist . '/assets/' . $name, $mtime);
    }

    /** @param list<string> $files */
    private function build(array $files): void
    {
        file_put_contents($this->dist . '/build-files.json', json_encode(['files' => $files]));
    }
}
