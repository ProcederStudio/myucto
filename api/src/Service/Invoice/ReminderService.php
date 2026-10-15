<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;

/**
 * Sdílená logika pro odeslání upomínky — používá:
 *   - SendReminderAction (HTTP single)
 *   - BulkSendRemindersAction (HTTP bulk)
 *   - bin/cron-send-reminders.php (CLI)
 *
 * Validace: faktura musí být ve stavu 'issued'/'sent'/'reminded' a po splatnosti.
 * Po úspěchu: status = 'reminded', last_reminder_at = NOW(), reminder_count++.
 */
final class ReminderService
{
    /**
     * Klient bez e-mailu je legitimní stav (#221) — cron to hlásí jako přeskočeno,
     * ne jako chybu, jinak by u každého takového klienta šuměl v logu každý běh.
     */
    public const NO_RECIPIENT_MESSAGE = 'Klient nemá vyplněný email.';

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly InvoicePdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly ActivityLogger $logger,
        private readonly RecipientResolver $recipients,
    ) {}

    /**
     * @param list<string>|null $to  ruční příjemci z modalu (#60); null = jen resolver
     * @param list<string> $cc  bez `$to` se přidají k vyřešeným, s `$to` jsou celý seznam
     * @param list<string> $bcc dtto
     * @return array{sent_to: string[], cc: string[], bcc: string[], days_overdue: int}
     * @throws \RuntimeException při chybě (recipient/PDF/SMTP/...)
     * @throws \DomainException když faktura nesplňuje podmínky pro upomínku
     * @throws \InvalidArgumentException při neplatné ručně zadané adrese
     */
    public function send(
        int $invoiceId,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?array $to = null,
        array $cc = [],
        array $bcc = [],
    ): array {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \DomainException('Faktura nenalezena.');
        }

        if (!in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)) {
            throw new \DomainException('Upomínku lze poslat jen u nezaplacené vystavené/odeslané faktury.');
        }
        if (!in_array($invoice['invoice_type'], ['invoice', 'proforma'], true)) {
            throw new \DomainException('Upomínat lze jen běžnou fakturu nebo proformu (ne dobropis/storno).');
        }
        if (!InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)) {
            throw new \DomainException(InvoiceAmountPolicy::NON_POSITIVE_REMINDER_MESSAGE);
        }
        // Cokoliv jiného než bankovní převod (karta, hotovost, inkaso, dobírka, zápočet,
        // jiné — migrace 1128) = úhrada mimo převod. QR ani bankovní spojení se na
        // PDF/emailu nepoužívají; upomínka „zaplaťte převodem" by klienta jen mátla.
        // U inkasa je to navíc věcně špatně — peníze si strháváme sami.
        // Cron filtruje už v SQL (`payment_method = 'bank_transfer'`, tedy pozitivní
        // whitelist → nové hodnoty jsou vyloučené automaticky); tahle pojistka chrání
        // i bulk/manual cestu.
        $paymentMethod = (string) ($invoice['payment_method'] ?? 'bank_transfer');
        if ($paymentMethod !== 'bank_transfer') {
            throw new \DomainException('Upomínat lze jen faktury s platbou bankovním převodem.');
        }

        $today = new \DateTimeImmutable('today');
        $due   = new \DateTimeImmutable((string) $invoice['due_date']);
        if ($due >= $today) {
            throw new \DomainException('Faktura ještě není po splatnosti.');
        }
        $daysOverdue = (int) $today->diff($due)->days;

        // Jednotný resolver (#86): účel `reminders`, fallback na `documents`,
        // bez kontaktů legacy chování (main_email + e-maily zakázky), včetně
        // kopie dodavateli (supplier.self_copy / cfg smtp.cc_supplier_on_reminder).
        // Ruční příjemci z modalu (#60) — typicky člověk, který platbu schvaluje,
        // přidaný bez zásahu do uložených kontaktů klienta.
        $manualRecipients = $to !== null || $cc !== [] || $bcc !== [];
        $r = RecipientResolver::applyOverrides(
            $this->recipients->resolve(RecipientResolver::TYPE_REMINDERS, $invoice),
            $to,
            $cc,
            $bcc,
        );
        $to = $r['to'];
        $cc = $r['cc'];
        $bcc = $r['bcc'];
        if (empty($to)) {
            throw new \DomainException(self::NO_RECIPIENT_MESSAGE);
        }
        foreach ([...$to, ...$cc, ...$bcc] as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Neplatný email: $em");
            }
        }

        $locale = (string) ($invoice['language'] ?? 'cs');

        // Reálné selhání (PDF/SMTP) zalogujeme jako `invoice.reminder_failed`, ať je
        // v přehledu odeslaných e-mailů vidět „nebylo odesláno". Validační DomainException
        // výše se sem nedostanou (házejí dřív). Po zalogování chybu propustíme dál —
        // caller (manual/bulk/cron) si ji ošetří jako dosud.
        try {
            $pdfPath = $this->renderer->render($invoiceId, false, $userId);
            $vars = $this->varsBuilder->buildReminder($invoice, $daysOverdue, $locale);
            $templateCode = $invoice['invoice_type'] === 'proforma' ? 'proforma_reminder' : 'invoice_reminder';
            $this->mailer->sendTemplate(
                $templateCode,
                $locale,
                $to,
                $vars,
                null,
                $cc,
                $bcc,
                [$this->varsBuilder->pdfAttachment($invoice, $pdfPath)],
                $userId,
                templateSupplierId: (int) $invoice['supplier_id'],
            );
        } catch (\Throwable $e) {
            $this->logger->log('invoice.reminder_failed', $userId, 'invoice', $invoiceId, [
                'to'           => $to,
                'cc'           => $cc,
                'days_overdue' => $daysOverdue,
                'error'        => mb_substr($e->getMessage(), 0, 500),
            ], $ip, $userAgent);
            throw $e;
        }

        // Status → 'reminded' (z 'paid' nepřechází, protože jsme to vyloučili výše)
        $this->db->pdo()->prepare(
            "UPDATE invoices
                SET status = 'reminded',
                    last_reminder_at = NOW(),
                    reminder_count = reminder_count + 1
              WHERE id = ?"
        )->execute([$invoiceId]);

        $this->logger->log('invoice.reminder_sent', $userId, 'invoice', $invoiceId, [
            'to'           => $to,
            'cc'           => $cc,
            'bcc'          => $bcc,
            'resolved_recipients' => $r['resolved'],
            'manual_recipients' => $manualRecipients,
            'days_overdue' => $daysOverdue,
            'reminder_no'  => (int) $invoice['reminder_count'] + 1,
        ], $ip, $userAgent);

        return ['sent_to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'days_overdue' => $daysOverdue];
    }
}
