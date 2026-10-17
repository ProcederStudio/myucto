<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyInstant
{
    public static function normalize(mixed $input): string
    {
        if (!is_string($input) || !preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?(Z|[+-][0-9]{2}:[0-9]{2})$/D', $input, $match)) self::invalid();
        $zone = $match[3] === 'Z' ? '+00:00' : $match[3];
        $hours = (int) substr($zone, 1, 2);
        $minutes = (int) substr($zone, 4, 2);
        if ($zone === '-00:00' || $hours > 14 || $minutes > 59 || ($hours === 14 && $minutes !== 0) || substr($match[1], 0, 4) === '0000') self::invalid();
        $text = $match[1] . '.' . str_pad($match[2] ?? '', 6, '0') . $zone;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $text);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\TH:i:s.uP') !== $text) self::invalid();
        $utc = $date->setTimezone(new \DateTimeZone('UTC'));
        if ((int) $utc->format('Y') < 1000 || (int) $utc->format('Y') > 9999) self::invalid();
        return $utc->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function interval(mixed $from, mixed $to): array
    {
        $from = self::normalize($from);
        $to = $to === null ? null : self::normalize($to);
        if ($to !== null && $from >= $to) throw new PropertyException('property_invalid_interval', 'Konec účinnosti musí následovat po začátku.');
        return [$from, $to];
    }

    public static function contains(string $from, ?string $to, string $at): bool
    {
        [$from, $to] = self::interval($from, $to);
        $at = self::normalize($at);
        return $from <= $at && ($to === null || $at < $to);
    }

    public static function sql(string $instant): string
    {
        return str_replace('T', ' ', substr(self::normalize($instant), 0, -1));
    }

    private static function invalid(): never
    {
        throw new PropertyException('property_invalid_instant', 'Uveďte platný okamžik včetně času a časového posunu. Samotné datum nestačí.');
    }
}
