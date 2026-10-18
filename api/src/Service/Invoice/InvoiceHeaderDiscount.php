<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/** Jediný výpočet záporných řádků hlavičkové slevy vydané faktury. */
final class InvoiceHeaderDiscount
{
    /**
     * @param list<array<string,mixed>> $items normalizované položky včetně vypočteného kódu DPH a OSS parametrů
     * @return array<string,array{vat_rate_id:int,snapshot:float,code:?string,base:float,oss?:array<mixed>}>
     */
    public static function groups(array $items, bool $supportsOss): array
    {
        $groups = [];
        foreach ($items as $item) {
            if (InvoiceItemKind::of($item) !== InvoiceItemKind::STANDARD) {
                continue;
            }
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $snapshot = (float) ($item['vat_rate_snapshot'] ?? 0);
            $code = $item['vat_classification_code'] ?? null;
            $oss = (array) ($item['_header_discount_oss_params'] ?? []);
            $key = $vatRateId . '|' . ($code ?? '');
            if ($supportsOss) {
                $ossKey = $oss;
                $ossKey[6] = null;
                $ossKey[7] = null;
                $key .= '|' . implode('|', array_map(static fn ($value) => $value === null ? '' : (string) $value, $ossKey));
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'vat_rate_id' => $vatRateId,
                    'snapshot' => $snapshot,
                    'code' => $code !== null ? (string) $code : null,
                    'base' => 0.0,
                ];
                if ($supportsOss) {
                    $groups[$key]['oss'] = $oss;
                }
            } elseif ($supportsOss) {
                foreach ([6, 7] as $amountIndex) {
                    if (($oss[$amountIndex] ?? null) !== null) {
                        $groups[$key]['oss'][$amountIndex] =
                            (float) ($groups[$key]['oss'][$amountIndex] ?? 0.0) + (float) $oss[$amountIndex];
                    }
                }
            }
            $groups[$key]['base'] += TimeBilling::invoiceAmount($item);
        }
        return $groups;
    }

    /**
     * @param array<string,array{vat_rate_id:int,snapshot:float,code:?string,base:float,oss?:array<mixed>}> $groups
     * @return list<array{vat_rate_id:int,snapshot:float,code:?string,base:float,discount:float,oss?:array<mixed>}>
     */
    public static function discountGroups(array $groups, float $discountPercent): array
    {
        $out = [];
        foreach ($groups as $group) {
            $discount = round($group['base'] * $discountPercent / 100.0, 2);
            if ($discount == 0.0) {
                continue;
            }
            $out[] = $group + ['discount' => $discount];
        }
        return $out;
    }

    /**
     * @param array<string,array{vat_rate_id:int,snapshot:float,code:?string,base:float,oss?:array<mixed>}> $groups
     * @return list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float,item_kind:string}>
     */
    public static function calculationItems(array $groups, float $discountPercent): array
    {
        $items = [];
        foreach (self::discountGroups($groups, $discountPercent) as $group) {
            $items[] = [
                'quantity' => 1.0,
                'unit_price_without_vat' => -$group['discount'],
                'vat_rate_snapshot' => $group['snapshot'],
                'item_kind' => InvoiceItemKind::DISCOUNT,
            ];
        }
        return $items;
    }
}
