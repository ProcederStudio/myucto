<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * „Je faktura pro klienta zaplacená?" pro výstupy, které to klientovi tvrdí:
 * e-mail, PDF, veřejná stránka faktury a QR platba. Jeden zdroj pravdy, aby
 * e-mail neříkal „doplaťte" a PDF ke stejné faktuře „uhrazeno, neplaťte".
 *
 * Faktura je efektivně zaplacená, když má status 'paid' A zároveň:
 * - jde o starší doklad bez evidovaných plateb (paid_total ~ 0, stejná konvence
 *   jako InvoiceRepository::remainingSql(), doklady „paid" z dob před #89), nebo
 * - zůstatek NEPŘESAHUJE InvoicePaymentService::TOLERANCE. Schválně bez abs():
 *   záporný zůstatek je přeplatek, klient nic nedluží.
 *
 * Mimo tyhle případy se faktura chová jako neuhrazená, i když v DB status 'paid'
 * zůstal. DocumentLockService status 'paid' nezamyká, admin tak může zaplacené
 * faktuře přidat položky a nechat reálný doplatek.
 */
final class InvoicePaidState
{
    /** @param array<string,mixed> $invoice */
    public static function isEffectivelyPaid(array $invoice): bool
    {
        if (($invoice['status'] ?? '') !== 'paid') {
            return false;
        }
        if (abs((float) ($invoice['paid_total'] ?? 0)) < 0.005) {
            return true;
        }

        return self::remaining($invoice) <= InvoicePaymentService::TOLERANCE;
    }

    /**
     * Zbývá doplatit: amount_to_pay − paid_total (částečné úhrady, #89). U efektivně
     * zaplacené faktury 0, ať výstup vedle „uhrazeno" nežádá zbytek v toleranci.
     *
     * @param array<string,mixed> $invoice
     */
    public static function amountDue(array $invoice): float
    {
        return self::isEffectivelyPaid($invoice) ? 0.0 : self::remaining($invoice);
    }

    /** @param array<string,mixed> $invoice */
    private static function remaining(array $invoice): float
    {
        return round(
            (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat'] ?? 0) - (float) ($invoice['paid_total'] ?? 0),
            2,
        );
    }
}
