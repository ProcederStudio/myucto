<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Zaokrouhlení částky k úhradě na celé koruny podle režimu uloženého na dokladu.
 * Výchozí režim nových dokladů je volba firmy (`supplier.default_rounding_mode`,
 * migrace 2031).
 */
final class InvoiceRounding
{
    public const MODES = ['auto', 'none', 'whole_czk'];

    /** Výchozí režim nového dokladu, když firma nic nezvolila nebo volba chybí. */
    public const DEFAULT_MODE = 'auto';

    public static function normalize(mixed $mode): string
    {
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Neplatný způsob zaokrouhlení faktury.');
        }
        return $mode;
    }

    /**
     * Režim, který dostane nová faktura firmy, když ho volající nepošle. Jediné místo,
     * které volbu firmy čte: zakládání v API/editoru ({@see InvoiceDefaults}), pravidelná
     * fakturace i faktura z objednávky. Neznámá nebo chybějící hodnota = {@see DEFAULT_MODE}.
     */
    public static function supplierDefault(\PDO $pdo, int $supplierId): string
    {
        if ($supplierId <= 0) {
            return self::DEFAULT_MODE;
        }
        $stmt = $pdo->prepare('SELECT default_rounding_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return self::orDefault($stmt->fetchColumn());
    }

    public static function orDefault(mixed $mode): string
    {
        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    public static function adjustment(float $payable, string $mode, string $currency, string $paymentMethod, string $invoiceType): float
    {
        self::normalize($mode);
        if ($currency !== 'CZK' || $paymentMethod === 'card'
            || !in_array($invoiceType, ['invoice', 'credit_note'], true)
            || $mode === 'none' || ($mode === 'auto' && $paymentMethod !== 'cash')) {
            return 0.0;
        }
        $payable = round($payable, 2, PHP_ROUND_HALF_UP);
        return round(round($payable, 0, PHP_ROUND_HALF_UP) - $payable, 2, PHP_ROUND_HALF_UP);
    }
}
