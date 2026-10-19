<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonCompositeHash;
use MyInvoice\Service\Anonymization\Streaming\JsonStreamValue;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonCompositeHashTest extends TestCase
{
    public function testEmbeddedGrowingJsonHasExactLegacyRecordHashWithoutAnnualArrayContract(): void
    {
        $rows = [];
        for ($id = 1; $id <= 1001; $id++) {
            $rows[] = ['id' => $id, 'amount_cents' => (string) ($id * 17), 'evidence' => 'Žluťoučký ' . $id];
        }
        $embedded = json_encode(['rows' => $rows, 'summary' => ['amount_cents' => '851?']], JSON_THROW_ON_ERROR);
        $chunks = static function () use ($embedded): iterable {
            for ($offset = 0, $length = strlen($embedded); $offset < $length; $offset += 37) {
                yield substr($embedded, $offset, 37);
            }
        };
        $record = [
            'supplier_id' => 7,
            'source_id' => 300,
            'amount_cents' => '19000',
            'basis' => json_decode($embedded, true, flags: JSON_THROW_ON_ERROR),
            'created_by' => 1,
        ];

        $actual = (new CanonicalJsonCompositeHash())->hash([
            'supplier_id' => 7,
            'source_id' => 300,
            'amount_cents' => '19000',
            'basis' => new JsonStreamValue($chunks),
            'created_by' => 1,
        ]);

        $legacy = NonprofitTaxRules::canonicalJson($record);
        self::assertSame(hash('sha256', $legacy), $actual->sha256);
        self::assertSame(strlen($legacy), $actual->bytes);
        self::assertGreaterThan(1001, $actual->nodes);
    }

    public function testCompositeRejectsUnboundedPhpArrayValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JsonStreamValue');

        (new CanonicalJsonCompositeHash())->hash(['basis' => ['forbidden' => true]]);
    }
}
