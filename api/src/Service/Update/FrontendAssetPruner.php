<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Úklid assetů frontendu z předchozích vydání ve `web/dist/assets/`.
 *
 * Nativní update i ruční nasazení (`tar -xzf` přes instalaci) rozbalí nový bundle přes
 * starý strom a soubory, které v novém bundlu nejsou, nemažou. Jména assetů nesou otisk
 * obsahu, takže každé vydání přidá stovky nových souborů a staré zůstanou navždy.
 *
 * Seznam souborů buildu zapisuje Vite do `web/dist/build-files.json`
 * (`web/plugins/build-files.mjs`). Zůstávají soubory aktuálního i předchozího buildu
 * (otevřená záložka se starou verzí si dotahuje chunky dál) a soubory mladší než
 * {@see self::GRACE_SECONDS}. Bundle bez seznamu se neuklízí vůbec.
 */
final class FrontendAssetPruner
{
    public const BUILD_FILES = 'build-files.json';
    public const STATE_FILE = 'frontend-builds.json';
    private const GRACE_SECONDS = 86400;

    public function __construct(
        private readonly string $distDir,
        private readonly string $stateFile,
    ) {}

    public static function forInstallation(): self
    {
        return new self(Bootstrap::rootDir() . '/web/dist', RuntimePaths::storage(self::STATE_FILE));
    }

    /** @return array{removed:int, bytes:int, skipped:?string} */
    public function prune(?int $now = null): array
    {
        $build = $this->readBuild();
        if ($build === null) {
            return ['removed' => 0, 'bytes' => 0, 'skipped' => 'web/dist nemá seznam souborů buildu.'];
        }
        $state = $this->readState();
        if (($state['current']['id'] ?? null) !== $build['id']) {
            $state = ['current' => $build, 'previous' => $state['current'] ?? null];
            $this->writeState($state);
        }
        $keep = array_flip(array_merge($build['files'], $state['previous']['files'] ?? []));
        $limit = ($now ?? time()) - self::GRACE_SECONDS;

        $removed = 0;
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->distDir . '/assets', \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $rel = 'assets/' . str_replace('\\', '/', substr($file->getPathname(), strlen($this->distDir . '/assets/')));
            if (isset($keep[$rel]) || $file->getMTime() >= $limit) {
                continue;
            }
            $size = $file->getSize();
            if (@unlink($file->getPathname())) {
                $removed++;
                $bytes += $size;
            }
        }
        return ['removed' => $removed, 'bytes' => $bytes, 'skipped' => null];
    }

    /** @return array{id:string, files:list<string>}|null */
    private function readBuild(): ?array
    {
        $path = $this->distDir . '/' . self::BUILD_FILES;
        if (!is_file($path) || !is_dir($this->distDir . '/assets')) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $data = json_decode($raw, true);
        $files = is_array($data) && is_array($data['files'] ?? null)
            ? array_values(array_filter($data['files'], static fn ($f): bool => is_string($f) && str_starts_with($f, 'assets/')))
            : [];
        // Prázdný nebo poškozený seznam by znamenal smazat všechno.
        return $files === [] ? null : ['id' => hash('sha256', $raw), 'files' => $files];
    }

    /** @return array{current?:array{id:string,files:list<string>}, previous?:?array{id:string,files:list<string>}} */
    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->stateFile), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $this->stateFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($state, JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }
        if (!@rename($tmp, $this->stateFile)) {
            @unlink($tmp);
        }
    }
}
