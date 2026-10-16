<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyUctoRegression\Manifest;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionManifestTest extends TestCase
{
    public function testDppoProviderReceivesInputsInsteadOfReadOnlyEnvelope(): void
    {
        $container = new class {
            public function get(string $class): object
            {
                return new class {
                    public function readOnlyInputs(int $supplier, int $year, string $type): array
                    {
                        return ['inputs' => ['bank_account_id' => 5, 'tax_paid_advances' => 1234.56], 'status' => 'draft', 'advances_source' => 'return'];
                    }
                    public function gather(int $supplier, int $year, array $inputs): array
                    {
                        return $inputs;
                    }
                };
            }
        };
        self::assertSame(['bank_account_id' => 5, 'tax_paid_advances' => 1234.56], Manifest::invoke($container, ['kind' => 'dppo_data', 'args' => [1, 2025]]));
    }
}
