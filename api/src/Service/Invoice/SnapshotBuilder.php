<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;

/**
 * Při vystavení faktury (issue) zapíše snapshoty klienta, dodavatele a banky.
 * Snapshoty jsou v JSON sloupcích `client_snapshot`, `supplier_snapshot`, `bank_snapshot`.
 *
 * Důvod: pokud později uživatel změní adresu klienta nebo bankovní účet,
 * VYSTAVENÉ faktury musí zachovat údaje platné v okamžiku vystavení.
 *
 * Plátcovství DPH dodavatele se mrazí k ROZHODNÉMU DATU dokladu ($vatDate,
 * typicky tax_date ?? issue_date) přes VatStatusService — živý supplier.is_vat_payer
 * je jen cache dneška a u zpětně datovaného dokladu by lhal.
 */
final class SnapshotBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * @param ?string $vatDate Rozhodné datum dokladu (YYYY-MM-DD) pro plátcovství DPH; null = dnešek.
     * @return array{client: array, supplier: array, bank: ?array}
     */
    public function build(int $clientId, int $currencyId, int $supplierId, ?int $brandingProfileId = null, ?string $vatDate = null): array
    {
        return [
            'client'   => $this->clientSnapshot($clientId),
            'supplier' => $this->supplierSnapshot($supplierId, $brandingProfileId, $vatDate),
            'bank'     => $this->bankSnapshot($currencyId),
        ];
    }

    private function clientSnapshot(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c
               JOIN countries co ON co.id = c.country_id
              WHERE c.id = ?'
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException("Client #$clientId nenalezen");
        }
        return [
            'company_name' => $row['company_name'],
            'first_name'   => $row['first_name'],
            'last_name'    => $row['last_name'],
            'ic'           => $row['ic'],
            'dic'          => $row['dic'],
            // Národní daňové číslo (#120): SK DIČ / DE Steuernummer / PL NIP / HU Adószám;
            // u SK klienta `dic` nese IČ DPH (SK+číslo) a `tax_number` DIČ bez prefixu.
            'tax_number'   => $row['tax_number'] ?? null,
            'street'       => $row['street'],
            'city'         => $row['city'],
            'zip'          => $row['zip'],
            'country_iso2' => $row['country_iso2'],
            'country_name_cs' => $row['country_name_cs'],
            'country_name_en' => $row['country_name_en'],
            'main_email'   => $row['main_email'],
            'phone'        => $row['phone'],
            // Plátcovství klienta v okamžiku vystavení — živý stav JE snapshot k datu
            // vystavení (kontakty nemají tabulku historie, jen per-doklad snapshoty).
            'is_vat_payer' => $row['is_vat_payer'] !== null ? (bool) $row['is_vat_payer'] : null,
            // Systémový koncový zákazník (§ 30): PDF ho jako odběratele netiskne.
            'is_walk_in'   => !empty($row['is_walk_in']),
        ];
    }

    private function supplierSnapshot(int $supplierId, ?int $brandingProfileId, ?string $vatDate): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM supplier s
               JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException("Supplier #$supplierId nenalezen.");
        }
        $flags = $this->vatStatus->statusAt($supplierId, $vatDate ?? date('Y-m-d'));
        $snapshot = [
            'id'           => (int) $row['id'],
            'company_name' => $row['company_name'],
            'display_name' => $row['display_name'],
            'street'       => $row['street'],
            'city'         => $row['city'],
            'zip'          => $row['zip'],
            'country_iso2' => $row['country_iso2'],
            'country_name_cs' => $row['country_name_cs'],
            'country_name_en' => $row['country_name_en'],
            'ic'           => $row['ic'],
            'dic'          => $row['dic'],
            // Oba flagy k rozhodnému datu dokladu z historie (supplier_vat_status_history,
            // migrace 1181 historizuje i identifikovanou osobu) — PDF podle is_identified
            // volí RC klauzuli a potlačí „Není plátce DPH" na zahraničním RC dokladu.
            'is_vat_payer' => $flags['is_vat_payer'],
            'is_identified' => $flags['is_identified'],
            'email'        => $row['email'],
            'phone'        => $row['phone'],
            'web'          => $row['web'],
            'tagline'      => $row['tagline'] ?? null,
            'commercial_register' => $row['commercial_register'] ?? null,
        ];

        if ($brandingProfileId === null) {
            return $snapshot;
        }

        $profileStmt = $this->db->pdo()->prepare(
            'SELECT bp.* FROM branding_profiles bp
               JOIN supplier s ON s.id = bp.supplier_id AND s.branding_profiles_enabled = 1
              WHERE bp.id = ? AND bp.supplier_id = ? AND bp.is_active = 1'
        );
        $profileStmt->execute([$brandingProfileId, $supplierId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if (!$profile) {
            throw new \InvalidArgumentException("Brandingový profil #$brandingProfileId nenalezen.");
        }

        $snapshot = \MyInvoice\Service\Branding\BrandingProfileOverlay::apply($snapshot, $profile);
        $snapshot['branding_profile_name'] = $profile['name'];
        return $snapshot;
    }

    private function bankSnapshot(int $currencyId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, account_number, bank_code, bank_name, iban, bic
               FROM currencies WHERE id = ?'
        );
        $stmt->execute([$currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Pokud nejsou bank údaje vyplněné, vrať null
        $hasCzk = !empty($row['account_number']) && !empty($row['bank_code']);
        $hasIban = !empty($row['iban']);
        if (!$hasCzk && !$hasIban) {
            return null;
        }

        return [
            'currency'       => $row['code'],
            'account_number' => $row['account_number'],
            'bank_code'      => $row['bank_code'],
            'bank_name'      => $row['bank_name'],
            'iban'           => $row['iban'],
            'bic'            => $row['bic'],
        ];
    }
}
