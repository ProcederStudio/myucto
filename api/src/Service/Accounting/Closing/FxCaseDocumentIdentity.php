<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

final class FxCaseDocumentIdentity
{
    public static function resolve(string $type, int $id, array $documents): ?int
    {
        $seen = [];
        $kind = $type === 'invoice' ? 'invoice_type' : 'document_kind';
        $parent = $type === 'invoice' ? 'parent_invoice_id' : 'parent_purchase_invoice_id';
        while (isset($documents[$id])) {
            if (isset($seen[$id])) return null;
            $seen[$id] = true;
            $document = $documents[$id];
            if ($document[$kind] !== 'credit_note' || $document[$parent] === null) return $id;
            $next = (int) $document[$parent];
            if (!isset($documents[$next]) || $documents[$next]['issue_date'] > $document['issue_date'] || empty($document['partner_id']) || $documents[$next]['partner_id'] !== $document['partner_id']) return null;
            $id = $next;
        }
        return null;
    }
}
