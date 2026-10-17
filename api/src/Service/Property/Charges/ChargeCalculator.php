<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

final class ChargeCalculator
{
    public const ROUNDING = 'aggregate_half_up_largest_remainder_unit_id_v1';

    public static function calculate(array $rate, array $units): array
    {
        if (!in_array($rate['method'] ?? null, ['unit', 'floor_area', 'share', 'person', 'equal'], true)
            || !in_array($rate['mode'] ?? null, ['rate_per_basis', 'total_budget'], true)
            || ($rate['rounding'] ?? null) !== self::ROUNDING) throw new ChargeException('property_charge_method_invalid', 'Zvolte doloženou metodu a pravidlo zaokrouhlení.');
        $amount = ChargeFraction::decimalCents($rate['amount'] ?? null);
        if ($rate['mode'] === 'total_budget' && $amount['den'] !== '1') throw new ChargeException('property_charge_amount_invalid', 'Rozpočet musí být vyjádřen v celých haléřích.');
        $negative = str_starts_with($amount['num'], '-');
        $amount['num'] = ltrim($amount['num'], '-');
        $bases = []; $sum = ['num' => '0', 'den' => '1'];
        foreach ($units as $unit) {
            $id = $unit['id'] ?? null;
            if (!is_int($id) || $id <= 0 || isset($bases[$id])) throw new ChargeException('property_charge_units_invalid', 'Každá jednotka musí být ve výpočtu právě jednou.');
            $attribute = $rate['method'] === 'person' ? 'persons' : $rate['method'];
            $basis = in_array($attribute, ['unit','equal'], true) ? ['num'=>'1','den'=>'1'] : ($unit['attributes'][$attribute] ?? null);
            if (!is_array($basis)) throw new ChargeException('property_charge_basis_missing', 'Jednotce chybí doložený výpočetní základ.', details: ['unit_id'=>$id, 'attribute'=>$attribute]);
            $basis = \MyInvoice\Service\Property\PropertyRational::normalize($basis);
            $bases[$id] = $basis;
            $sum = ChargeFraction::add($sum, $basis);
        }
        if ($bases === []) throw new ChargeException('property_charge_units_invalid', 'Vyberte konkrétní jednotky rozhodnutí.');
        ksort($bases, SORT_NUMERIC);
        if ($rate['mode'] === 'total_budget' && $sum['num'] === '0' && $amount['num'] !== '0') throw new ChargeException('property_charge_zero_basis', 'Nenulový rozpočet nelze rozdělit podle nulového základu.');
        $exactTotal = ['num'=>'0','den'=>'1']; $lines = []; $floorTotal = '0';
        foreach ($bases as $id=>$basis) {
            $weight = $rate['mode'] === 'total_budget' && $sum['num'] !== '0'
                ? ChargeFraction::multiply($basis, ['num'=>$sum['den'],'den'=>$sum['num']]) : $basis;
            $exact = ChargeFraction::multiply($amount, $weight);
            $floor = bcdiv($exact['num'], $exact['den'], 0);
            $lines[] = ['unit_id'=>$id, 'basis'=>$basis, 'exact_cents'=>$exact, 'amount_cents'=>$floor,
                'residual_cent'=>false, 'remainder'=>['num'=>bcmod($exact['num'],$exact['den'],0),'den'=>$exact['den']]];
            $floorTotal = bcadd($floorTotal,$floor,0);
            $exactTotal = ChargeFraction::add($exactTotal,$exact);
        }
        $total = ChargeFraction::round($exactTotal);
        $remaining = (int) bcsub($total,$floorTotal,0);
        $order = array_keys($lines);
        usort($order, static function(int $a,int $b) use($lines): int {
            $x=$lines[$a]['remainder']; $y=$lines[$b]['remainder'];
            return -bccomp(bcmul($x['num'],$y['den'],0),bcmul($y['num'],$x['den'],0),0) ?: $lines[$a]['unit_id'] <=> $lines[$b]['unit_id'];
        });
        foreach (array_slice($order,0,$remaining) as $index) {
            $lines[$index]['amount_cents']=bcadd($lines[$index]['amount_cents'],'1',0);
            $lines[$index]['residual_cent']=true;
        }
        foreach ($lines as &$line) {
            unset($line['remainder']);
            if ($negative) {
                if ($line['amount_cents'] !== '0') $line['amount_cents']='-'.$line['amount_cents'];
                if ($line['exact_cents']['num'] !== '0') $line['exact_cents']['num']='-'.$line['exact_cents']['num'];
            }
        }
        unset($line);
        return ['rounding'=>self::ROUNDING, 'basis_total'=>$sum, 'total_cents'=>$negative && $total !== '0' ? '-'.$total : $total, 'lines'=>$lines];
    }
}
