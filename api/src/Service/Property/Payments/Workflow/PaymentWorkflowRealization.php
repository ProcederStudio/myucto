<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments\Workflow;

final class PaymentWorkflowRealization
{
    public static function cash(array $expected, int $id): array
    {
        foreach ($expected['lines'] as &$line) $line = self::line($line, $id);
        unset($line);
        if (isset($expected['credit']['line'])) $expected['credit']['line'] = self::line($expected['credit']['line'], $id);
        return $expected;
    }

    private static function line(array $line, int $id): array
    {
        foreach ($line['dimension_origins'] ?? [] as $type => $origin) {
            if ($origin === ['origin' => 'module', 'ref' => 'cash:']) $line['dimension_origins'][$type]['ref'] = 'cash:' . $id;
        }
        return $line;
    }
}
