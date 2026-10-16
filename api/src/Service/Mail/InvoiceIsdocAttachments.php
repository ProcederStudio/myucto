<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Service\Invoice\InvoiceIsdocDeliverySettings;
use MyInvoice\Service\Invoice\InvoiceIsdocService;
use Psr\Log\LoggerInterface;

/** Jedna brána pro ruční, testovací i automatické odesílání faktur a upomínek. */
final class InvoiceIsdocAttachments
{
    public function __construct(
        private readonly InvoiceIsdocDeliverySettings $settings,
        private readonly InvoiceIsdocService $isdoc,
        private readonly LoggerInterface $logger,
    ) {}

    public function forEmail(string $code, array $vars, array $attachments, ?int $userId = null): array
    {
        if (!in_array($code, ['invoice_send', 'invoice_reminder', 'proforma_reminder', 'invoice_payment_thanks'], true)
            || !is_array($vars['invoice'] ?? null)) {
            return $attachments;
        }
        // Fronta i dělení příjemců předávají už vytvořené XML dál beze změny.
        $hasPdf = false;
        foreach ($attachments as $attachment) {
            if (($attachment['invoice_isdoc'] ?? false) === true) {
                return $attachments;
            }
            $hasPdf = $hasPdf || ($attachment['contentType'] ?? '') === 'application/pdf';
        }
        $invoice = $vars['invoice'];
        if (!$hasPdf || !$this->settings->emailEnabled($invoice)) {
            return $attachments;
        }
        try {
            $xml = $this->isdoc->document($invoice, $userId);
        } catch (\Throwable $e) {
            // Samostatné ISDOC je volitelná příloha; jeho chyba nesmí zablokovat
            // PDF ani ostatní přílohy. Zpráva výjimky může obsahovat citlivá data.
            $this->logger->warning('mail.invoice_isdoc_attachment_failed', [
                'template' => $code,
                'invoice_id' => (int) ($invoice['id'] ?? 0),
                'supplier_id' => (int) ($invoice['supplier_id'] ?? 0),
                'exception_class' => $e::class,
            ]);
            return $attachments;
        }
        // XML je UTF-8 text. Uložení obsahu do outbox payloadu zachová přesný
        // podepsaný doklad při odložení a nepotřebuje dočasný soubor na disku.
        $attachments[] = [
            'content' => $xml,
            'name' => InvoiceIsdocService::filename($invoice),
            'contentType' => 'application/x-isdoc',
            'invoice_isdoc' => true,
        ];
        return $attachments;
    }
}
