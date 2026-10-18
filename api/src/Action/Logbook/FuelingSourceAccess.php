<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Sdílená ochrana vazeb tankování; právo knihy jízd neotevírá ostatní evidence. */
final class FuelingSourceAccess
{
    public const LINKS = [
        'source_purchase_invoice_id' => 'purchase_invoices',
        'source_item_id' => 'purchase_invoices',
        'source_cash_document_id' => 'cash',
        'source_bank_transaction_id' => 'bank',
        'source_journal_entry_id' => 'accounting',
    ];

    public static function candidatePermission(string $type): ?string
    {
        return match ($type) {
            'cash_document' => 'cash',
            'bank_transaction' => 'bank',
            'journal_entry' => 'accounting',
            default => null,
        };
    }

    public static function forbiddenChange(Request $request, array $body, array $before = []): bool
    {
        foreach (self::LINKS as $key => $permission) {
            if (!array_key_exists($key, $body) || RequestAuthorization::allows($request, $permission)) continue;
            // Prázdná vazba u nového záznamu nic neotevírá. U aktualizace musí
            // nepřístupné vazby zůstat vynechané, jinak by redigované null mazalo data.
            if (($body[$key] === null || $body[$key] === '') && empty($before[$key])) continue;
            return true;
        }
        return false;
    }

    public static function redact(Request $request, array $row): array
    {
        // Původ musíme vyhodnotit ještě před redakcí vazeb. `import` zahrnuje
        // také vytěžení banky, deníku a samostatného dokumentu (FuelingDocumentRef).
        $canReadRawText = self::canReadRawText($request, $row);
        $groups = [
            'purchase_invoices' => ['source_purchase_invoice_id', 'source_item_id', 'source_invoice_number'],
            'cash' => ['source_cash_document_id', 'source_cash_document_number'],
            'bank' => ['source_bank_transaction_id', 'source_bank_statement_id', 'source_bank_posted_at', 'source_bank_amount', 'card_last4'],
            'accounting' => ['source_journal_entry_id', 'source_journal_entry_number'],
        ];
        foreach ($groups as $permission => $keys) {
            if (RequestAuthorization::allows($request, $permission)) continue;
            foreach ($keys as $key) {
                if (array_key_exists($key, $row)) $row[$key] = null;
            }
        }
        if (!$canReadRawText) $row['raw_text'] = null;
        if (!RequestAuthorization::allows($request, 'purchase_invoices') || !RequestAuthorization::allows($request, 'cash')) {
            if (array_key_exists('vat_warning', $row)) $row['vat_warning'] = null;
        }
        return $row;
    }

    private static function canReadRawText(Request $request, array $row): bool
    {
        $sourcePermission = match ($row['source'] ?? '') {
            'cash' => 'cash',
            'invoice', 'axigon', 'axigon_ai' => 'purchase_invoices',
            'import' => !empty($row['source_bank_transaction_id']) ? 'bank'
                : (!empty($row['source_journal_entry_id']) ? 'accounting' : null),
            default => null,
        };
        // Samostatný dokument/import nemá uloženou jednoznačnou provenienci.
        if ($sourcePermission === null || !RequestAuthorization::allows($request, $sourcePermission)) return false;
        foreach (self::LINKS as $key => $permission) {
            if (!empty($row[$key]) && !RequestAuthorization::allows($request, $permission)) return false;
        }
        return true;
    }
}
