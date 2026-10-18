<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\PhpCliLocator;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use MyInvoice\Support\OpenBasedir;

final class HostedMcp
{
    public function __construct(
        private readonly Connection $db,
        private readonly TenantUrlResolver $urls,
        private readonly ManagedModeGuard $managed,
    ) {}

    public function enabled(): bool
    {
        if ($this->managedInstallation()) return false;
        $override = $this->envOverride();
        if ($override !== null) return $override;
        return (int) $this->db->pdo()->query('SELECT enabled FROM mcp_server_settings WHERE id = 1')->fetchColumn() === 1;
    }

    public function setEnabled(bool $enabled): void
    {
        if ($this->managedInstallation()) {
            throw new \LogicException('Serverový MCP není ve spravované instalaci dostupný.');
        }
        if ($this->envOverride() !== null) {
            throw new \LogicException('Nastavení MCP řídí proměnná prostředí.');
        }
        $stmt = $this->db->pdo()->prepare('UPDATE mcp_server_settings SET enabled = ? WHERE id = 1');
        $stmt->execute([$enabled ? 1 : 0]);
    }

    public function managedByEnvironment(): bool
    {
        return $this->envOverride() !== null;
    }

    /** Spravovaná instalace, ve které provozovatel most s Node nepřipravil. */
    public function managedInstallation(): bool
    {
        return $this->managed->isManaged() && !$this->managedRelay();
    }

    /**
     * Spravovaná instalace s mostem připraveným provozovatelem. Node tam běží
     * odděleně od aplikace (kontejner), takže se nepouští {@see NodeBridge}
     * s vlastním voláním PHP, ale {@see ManagedNodeRelay}. Mimo spravovaný
     * režim je vždy false a nic se nemění.
     */
    public function managedRelay(): bool
    {
        return $this->managed->isManaged()
            && trim((string) (getenv('MYINVOICE_MCP_NODE_BINARY') ?: '')) !== '';
    }

    /**
     * PHP CLI pro reléový most. Na sdíleném hostingu zakrývá `open_basedir`
     * binárky mimo web, proto se cesta neověřuje přes `is_file()` a hledá ji
     * {@see PhpCliLocator} spuštěním kandidátů.
     */
    public function relayPhpBinary(): ?string
    {
        $configured = trim((string) (getenv('MYINVOICE_MCP_PHP_BINARY') ?: ''));
        return $configured !== '' ? $configured : PhpCliLocator::resolve();
    }

    public function endpoint(): string
    {
        return $this->urls->canonicalBaseUrl() . '/mcp';
    }

    public function nodeBinary(): string
    {
        $configured = trim((string) (getenv('MYINVOICE_MCP_NODE_BINARY') ?: ''));
        return $configured !== '' ? $configured : 'node';
    }

    public function phpBinary(): string
    {
        $configured = trim((string) (getenv('MYINVOICE_MCP_PHP_BINARY') ?: ''));
        if ($configured !== '') return $configured;
        $name = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $nearby = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $name;
        return OpenBasedir::isFile($nearby) ? $nearby : PHP_BINDIR . DIRECTORY_SEPARATOR . $name;
    }

    public function nodeAvailable(): bool
    {
        if ($this->managedRelay()) {
            return function_exists('proc_open') && $this->relayPhpBinary() !== null;
        }
        if (!function_exists('proc_open')) return false;
        if (!OpenBasedir::isExecutableFile($this->phpBinary())) return false;
        $binary = $this->nodeBinary();
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return OpenBasedir::isExecutableFile($binary);
        }

        $path = (string) getenv('PATH');
        $suffixes = PHP_OS_FAMILY === 'Windows' ? ['.exe', '.cmd', '.bat', ''] : [''];
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            foreach ($suffixes as $suffix) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $binary . $suffix;
                if (OpenBasedir::isExecutableFile($candidate)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function envOverride(): ?bool
    {
        $raw = getenv('MYINVOICE_MCP_ENABLED');
        $value = strtolower(trim($raw === false ? '' : $raw));
        if (in_array($value, ['1', 'true', 'yes'], true)) return true;
        if (in_array($value, ['0', 'false', 'no'], true)) return false;
        return null;
    }
}
