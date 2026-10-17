<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/** Jediný PHP výklad částky řádku deníku včetně červeného storna. */
final class JournalLineAmount
{
    public static function decimal(float|int|string $amount): string
    {
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new \InvalidArgumentException('Částka musí být konečné číslo.');
            }
            return number_format($amount, 2, '.', '');
        }
        $value = trim((string) $amount);
        if (!preg_match('/^([+-]?)(\d+(?:\.\d*)?|\.\d+)(?:[eE]([+-]?\d+))?$/D', $value, $parts)) {
            throw new \InvalidArgumentException('Částka musí být číslo.');
        }
        if (isset($parts[3])) {
            $mantissa = $parts[2];
            $point = strpos($mantissa, '.');
            $point = $point === false ? strlen($mantissa) : $point;
            $digits = str_replace('.', '', $mantissa);
            $leading = strlen($digits) - strlen(ltrim($digits, '0'));
            $digits = substr($digits, $leading);
            if ($digits === '') {
                return '0.00';
            }
            $position = $point - $leading + (int) $parts[3];
            if ($position < -2) {
                return '0.00';
            }
            if ($position > 309) {
                throw new \InvalidArgumentException('Částka je mimo číselný rozsah.');
            }
            $value = $parts[1] . ($position <= 0
                ? '0.' . str_repeat('0', -$position) . $digits
                : substr(str_pad($digits, $position, '0'), 0, $position) . '.' . substr($digits, $position));
        }
        return bcround($value, 2);
    }

    public static function cents(float|int|string $amount): int
    {
        $cents = bcmul(self::decimal($amount), '100', 0);
        if (bccomp($cents, (string) PHP_INT_MAX, 0) > 0 || bccomp($cents, (string) PHP_INT_MIN, 0) < 0) {
            throw new \InvalidArgumentException('Částka je mimo rozsah haléřů.');
        }
        return (int) $cents;
    }

    /** @param array{amount:float|int|string,is_red_storno?:bool|int} $line */
    public static function signed(array $line): float
    {
        $amount = (float) $line['amount'];
        return !empty($line['is_red_storno']) ? -$amount : $amount;
    }

    /** @param array{amount:float|int|string,is_red_storno?:bool|int} $line */
    public static function signedCents(array $line): int
    {
        $cents = self::cents($line['amount']);
        return !empty($line['is_red_storno']) ? -$cents : $cents;
    }

    /**
     * Protizápis běžného řádku obrátí stranu. Červený řádek už je záporný,
     * proto jej vyruší kladný řádek na stejné straně.
     *
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    public static function reversal(array $line): array
    {
        if (empty($line['is_red_storno'])) {
            $line['side'] = $line['side'] === 'debit' ? 'credit' : 'debit';
        }
        $line['is_red_storno'] = false;
        return $line;
    }
}
