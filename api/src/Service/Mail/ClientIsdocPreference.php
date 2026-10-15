<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

/** NULL dědí nastavení dodavatele; obě booleovské hodnoty jsou výslovná volba klienta. */
final class ClientIsdocPreference
{
    public const INVALID_MESSAGE = 'Příloha ISDOC musí být true, false nebo null (nastavení dodavatele).';

    public static function isValid(mixed $value): bool
    {
        return $value === null || is_bool($value);
    }

    public static function toDatabase(mixed $value): ?int
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException(self::INVALID_MESSAGE);
        }
        return $value === null ? null : (int) $value;
    }
}
