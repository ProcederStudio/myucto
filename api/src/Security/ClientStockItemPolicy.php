<?php

declare(strict_types=1);

namespace MyInvoice\Security;

/** Úzký kontrakt klientské skladové karty; účetní a e-shop nastavení spravuje interní role. */
final class ClientStockItemPolicy
{
    public const EDITABLE_FIELDS = [
        'sku', 'name', 'item_type', 'unit', 'tracking_mode', 'ean', 'vat_rate_id',
        'sale_price_without_vat', 'min_qty', 'is_active', 'note', 'row_version',
    ];

    public static function forbiddenFields(array $body): array
    {
        return array_values(array_diff(array_keys($body), self::EDITABLE_FIELDS));
    }

    public static function item(array $item): array
    {
        return array_intersect_key($item, array_flip([
            ...self::EDITABLE_FIELDS, 'id', 'supplier_id', 'default_sale_unit', 'is_stocked',
            'lifecycle_status', 'retired_at', 'created_at', 'updated_at', 'qty', 'is_set', 'set_id',
            'effective_price', 'promo_price', 'promo_label', 'promo_qty_available',
            'units', 'matched_unit', 'has_customer_prices', 'search_match',
        ]));
    }

    /** Pro vychystání jsou potřebné identifikátory, kusy a doručení, nikoli ocenění. */
    public static function fulfillment(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array($key, ['unit_cost_snapshot', 'unit_cost', 'value_total', 'avg_unit_cost'], true)) {
                unset($payload[$key]);
                continue;
            }
            if ($key === 'source_snapshot' && is_array($value)) {
                $payload[$key] = array_intersect_key($value, array_flip([
                    'source_type', 'source_id', 'document_number', 'document_date', 'description', 'date', 'note',
                    'order_uuid', 'order_number', 'client_name', 'shipping_snapshot',
                ]));
            } elseif ($key === 'component_snapshot' && is_array($value)) {
                $payload[$key] = array_intersect_key($value, array_flip([
                    'source_type', 'source_id', 'source_line_id', 'stock_item_id',
                    'warehouse_id', 'sku', 'ean', 'name', 'unit', 'quantity', 'tracking_allocations',
                ]));
            } elseif (is_array($value)) {
                $payload[$key] = self::fulfillment($value);
            }
        }
        return $payload;
    }

}
