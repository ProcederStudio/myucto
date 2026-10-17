<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

/** Titul daňového subjektu FO; EPO DPH/KH/SH i DPFO dovolují nejvýš 10 znaků. */
final class SupplierPersonTitle
{
    public const MAX_LENGTH = 10;

    public static function normalize(mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException('person_title musí být text nebo null.');
        }
        $title = trim($value ?? '');
        if (mb_strlen($title) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('Titul OSVČ může mít nejvýše ' . self::MAX_LENGTH . ' znaků.');
        }
        return $title === '' ? null : $title;
    }
}
