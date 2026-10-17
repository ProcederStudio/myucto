<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyInput
{
    public static function text(mixed $value, string $field, int $max = 190, bool $optional = false): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > $max || (!$optional && trim($value) === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) self::invalid($field);
        return trim($value);
    }

    public static function id(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) self::invalid($field);
        return $value;
    }

    public static function code(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $value)) self::invalid($field);
        return $value;
    }

    public static function fields(array $input, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) self::invalid((string) $unknown[0]);
    }

    public static function list(mixed $value, string $field, int $max = 200): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) self::invalid($field);
        return $value;
    }

    public static function invalid(string $field): never
    {
        throw new PropertyException('property_invalid_input', 'Zkontrolujte označený vstup registru.', details: ['field' => $field]);
    }

    public static function hash(array $payload): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($sort, $value);
        };
        return hash('sha256', json_encode($sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
