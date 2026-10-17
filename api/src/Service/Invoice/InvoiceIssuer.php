<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Zápis vystavitele dokladu (`invoices.issued_by` + snapshot jména `issued_by_name`,
 * migrace 2032). Volá ho každá cesta, která doklad vystavuje jménem uživatele:
 * {@see \MyInvoice\Action\Invoice\IssueInvoiceAction}, {@see AutoIssueAndSendService},
 * ručně spuštěný {@see RecurringInvoiceGenerator} a interní storno v
 * {@see \MyInvoice\Action\Invoice\CancelInvoiceAction}.
 *
 * Statické a nad PDO schválně: volá se uvnitř transakce vystavení a generátor
 * pravidelné fakturace testy skládají ručně, nová závislost v konstruktoru by je rozbila.
 */
final class InvoiceIssuer
{
    /**
     * Bez uživatele (schválení výkazu klientem přes veřejný odkaz, cron, systémová akce)
     * nechá vystavitele prázdného; PDF pak bere autora dokladu.
     */
    public static function record(\PDO $pdo, int $invoiceId, ?int $userId): void
    {
        if ($userId === null || $userId <= 0) {
            return;
        }
        // Přes JOIN: neexistující uživatel nesmí shodit vystavení na cizím klíči,
        // doklad pak zůstane bez vystavitele.
        $pdo->prepare(
            'UPDATE invoices i
               JOIN users u ON u.id = ?
                SET i.issued_by = u.id,
                    i.issued_by_name = u.name
              WHERE i.id = ?'
        )->execute([$userId, $invoiceId]);
    }
}
