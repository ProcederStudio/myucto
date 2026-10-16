<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

/**
 * Vybraná sériová čísla a šarže na řádku faktury (`invoice_items.tracking_json`,
 * migrace 2012).
 *
 * API řádku nese `tracking_allocations` ve stejném tvaru jako řádek skladového dokladu
 * ({@see TrackingAllocationService::normalizeDraftInput()}), jen bez
 * `stock_tracking_unit_id` a `location_to_id`: identita se ukládá textem, aby archiv
 * a obnova firmy nemusely sloupec přemapovávat, a převodka na faktuře nemá smysl.
 * Úplnost výběru (součet = množství řádku) a dostupnost kusů se kontroluje až při
 * vystavení, rozepsaný koncept smí mít výběr neúplný.
 */
final class InvoiceItemTracking
{
    private const STORED_KEYS = ['quantity', 'serial_number', 'lot_code', 'expires_on', 'location_id'];

    public function __construct(private readonly TrackingAllocationService $allocations) {}

    /**
     * Položky z těla požadavku: `tracking_allocations` → ověřený `tracking_json`.
     * `tracking_json` poslaný klientem se zahodí, jinak by obešel validaci.
     *
     * @param array<int,mixed> $items
     * @return array<int,mixed>
     * @throws StockException
     */
    public function normalizeItems(int $supplierId, array $items): array
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = $item['tracking_allocations'] ?? null;
            unset($item['tracking_allocations'], $item['tracking_json']);
            $item['tracking_json'] = $this->normalize($supplierId, $item, $raw, (int) $index);
            $items[$index] = $item;
        }
        return $items;
    }

    /**
     * Uložený výběr řádku pro zápis: `tracking_json` z {@see normalizeItems()}, jinak
     * výběr načtený z databáze (`tracking_allocations`), který už ověřený je.
     *
     * @param array<string,mixed> $item
     */
    public static function storedJson(array $item): ?string
    {
        if (array_key_exists('tracking_json', $item)) {
            return is_string($item['tracking_json']) && $item['tracking_json'] !== '' ? $item['tracking_json'] : null;
        }
        $allocations = $item['tracking_allocations'] ?? null;
        return is_array($allocations) && $allocations !== [] ? self::encode($allocations) : null;
    }

    /**
     * @return list<array{quantity:string, serial_number:?string, lot_code:?string, expires_on:?string, location_id:?int}>
     */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'quantity' => (string) ($row['quantity'] ?? '0'),
                'serial_number' => isset($row['serial_number']) ? (string) $row['serial_number'] : null,
                'lot_code' => isset($row['lot_code']) ? (string) $row['lot_code'] : null,
                'expires_on' => isset($row['expires_on']) ? (string) $row['expires_on'] : null,
                'location_id' => isset($row['location_id']) && (int) $row['location_id'] > 0 ? (int) $row['location_id'] : null,
            ];
        }
        return $out;
    }

    /** @param array<int,mixed> $allocations */
    private static function encode(array $allocations): string
    {
        $rows = [];
        foreach ($allocations as $row) {
            if (is_array($row)) {
                $rows[] = array_intersect_key($row, array_flip(self::STORED_KEYS));
            }
        }
        return json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $item */
    private function normalize(int $supplierId, array $item, mixed $raw, int $index): ?string
    {
        if ($raw === null || $raw === []) {
            return null;
        }
        if (!is_array($raw)) {
            throw new StockException('invalid_tracking_allocations', 'Výběr sériových čísel nebo šarží musí být seznam.', 422, ['index' => $index]);
        }
        $stockItemId = is_numeric($item['stock_item_id'] ?? null) ? (int) $item['stock_item_id'] : 0;
        if ($stockItemId <= 0) {
            throw new StockException('tracking_not_enabled', 'Sériová čísla nebo šarže jde vybrat jen u řádku se skladovou kartou.', 422, ['index' => $index]);
        }
        try {
            $json = $this->allocations->normalizeDraftInput($supplierId, $stockItemId, $raw);
        } catch (StockException $e) {
            throw new StockException($e->errorCode, $e->getMessage(), $e->httpStatus, ['index' => $index] + $e->details);
        }
        return $json === null ? null : self::encode((array) json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }
}
