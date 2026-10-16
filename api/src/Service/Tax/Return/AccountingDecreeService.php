<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;

final class AccountingDecreeService
{
    public function __construct(private readonly Connection $db) {}

    public function forDate(int $supplierId, string $date): string
    {
        $mode = (new AccountingModeRepository($this->db))->forDate($supplierId, $date);
        if ($mode === 'double_entry') {
            $frameworks = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
            return match ($frameworks->forDate($supplierId, $date)) {
                AccountingFrameworkService::CZ_504 => '504',
                default => '500',
            };
        }

        $stmt = $this->db->pdo()->prepare('SELECT tax_accounting_decree FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return trim((string) ($stmt->fetchColumn() ?: TaxpayerTypeCodebook::SUPPORTED_DECREE));
    }

    public function forYear(int $supplierId, int $year): string
    {
        $period = (new AccountingPeriodRepository($this->db))->findByYear($supplierId, $year);
        return $this->forDate($supplierId, (string) ($period['starts_on'] ?? sprintf('%04d-01-01', $year)));
    }

    public function assertDppoSupported(int $supplierId, int $year): string
    {
        $period = (new AccountingPeriodRepository($this->db))->findByYear($supplierId, $year);
        return $this->assertDppoPeriodSupported(
            $supplierId,
            (string) ($period['starts_on'] ?? sprintf('%04d-01-01', $year)),
            (string) ($period['ends_on'] ?? sprintf('%04d-12-31', $year)),
        );
    }

    public function assertDppoPeriodSupported(int $supplierId, string $startsOn, string $endsOn): string
    {
        $decree = $this->forDate($supplierId, $startsOn);
        self::assertSupportedDecree($decree);
        self::assertSupportedDecree($this->forDate($supplierId, $endsOn));
        $dates = (new AccountingModeRepository($this->db))->effectiveDatesBetween($supplierId, $startsOn, $endsOn);
        foreach ((new AccountingFrameworkRepository($this->db))->listForTenant($supplierId) as $row) {
            if ($row['effective_from'] > $startsOn && $row['effective_from'] < $endsOn) {
                $dates[] = $row['effective_from'];
            }
        }
        foreach (array_unique($dates) as $date) {
            self::assertSupportedDecree($this->forDate($supplierId, $date));
        }
        return $decree;
    }

    public function assertDppoSubmissionSupported(int $supplierId, array $submission): void
    {
        if (strtolower((string) ($submission['form_code'] ?? '')) !== 'dppdp9') {
            return;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = (string) ($submission['xml_content'] ?? '');
            $loaded = $xml !== '' && $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $header = $loaded ? (new \DOMXPath($dom))->query('/Pisemnost/DPPDP9/VetaD')?->item(0) : null;
        if ($header instanceof \DOMElement) {
            self::assertSupportedDecree($header->getAttribute('uv_vyhl'));
            $startsOn = $this->xmlDate($header->getAttribute('zdobd_od'));
            $endsOn = $this->xmlDate($header->getAttribute('zdobd_do'));
            if ($startsOn !== null && $endsOn !== null) {
                $this->assertDppoPeriodSupported($supplierId, $startsOn, $endsOn);
                return;
            }
        }
        $this->assertDppoSupported($supplierId, (int) $submission['period_year']);
    }

    private function xmlDate(string $date): ?string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', trim($date), $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int) $parts[3], (int) $parts[2], (int) $parts[1]);
    }

    public static function assertSupportedDecree(string $decree): void
    {
        if ($decree === '504') {
            throw new TaxReturnException(
                'dppo_accounting_framework_not_supported',
                'Výpočet, export a podání DPPO podle vyhlášky 504 zatím nejsou podporovány. Přiznání zpracujte externě; potvrzenou daň lze zaúčtovat ručně.',
                422,
            );
        }
    }
}
