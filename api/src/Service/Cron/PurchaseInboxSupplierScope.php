<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use RuntimeException;

/** Globální inbox má pro automatický sken právě jednu vlastní firmu. */
final class PurchaseInboxSupplierScope
{
    public function resolve(PDO $pdo, Config $config): ?int
    {
        $owner = $config->get('purchase_invoice.cron_supplier_id');
        if ($owner !== null) {
            return $this->configuredSupplier($pdo, $owner, 'purchase_invoice.cron_supplier_id');
        }

        // Historický výběr zachováme jen pro výslovně nakonfigurovanou firmu.
        // scan_all_suppliers nesmí stejný adresář importovat do více firem.
        $legacyOwner = $config->get('app.default_supplier_id');
        if (!(bool) $config->get('app.scan_all_suppliers', false) && $legacyOwner !== null) {
            return $this->configuredSupplier($pdo, $legacyOwner, 'app.default_supplier_id');
        }

        $ids = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 1) {
            throw new RuntimeException(
                'Inbox má společný adresář pro více firem. Nastavte purchase_invoice.cron_supplier_id '
                . 'na ID firmy, které patří inbox_dir; app.scan_all_suppliers zde nelze použít pro sken všech firem.'
            );
        }

        return $ids === [] ? null : (int) $ids[0];
    }

    private function configuredSupplier(PDO $pdo, mixed $value, string $key): int
    {
        $id = (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($id === false) {
            throw new RuntimeException("{$key} musí být kladné celé ID existující firmy; nastavte purchase_invoice.cron_supplier_id.");
        }

        $stmt = $pdo->prepare('SELECT id FROM supplier WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Firma z {$key} neexistuje; opravte purchase_invoice.cron_supplier_id.");
        }

        return $id;
    }
}
