<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonProfile;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Anonymization\Streaming\JsonPointerStream;
use MyInvoice\Service\Anonymization\Streaming\JsonStreamValue;
use PHPUnit\Framework\TestCase;

final class JsonPointerStreamTest extends TestCase
{
    public function testPointerProjectionExcludesAndReplacesWithoutMaterializingSiblingVector(): void
    {
        $json = '{"ignored":[' . implode(',', array_fill(0, 1001, '{"text":"Příliš žluťoučký"}'))
            . '],"proof":{"z":3,"rows":[{"id":"a","amount_cents":50010},{"id":"b","amount_cents":70020}],'
            . '"hash":"old","nested":{"remove":"secret","keep":"ano"}}}';
        $pointer = new JsonPointerStream(static function () use ($json): iterable {
            for ($offset = 0; $offset < strlen($json); $offset += 31) {
                yield substr($json, $offset, 31);
            }
        });
        $projection = $pointer->at('/proof', ['/nested/remove'], [
            '/hash' => 'new',
            '/nested/keep' => new JsonStreamValue(static function (): iterable { yield '"změněno"'; }),
        ]);
        $output = fopen('php://temp', 'w+b');
        self::assertIsResource($output);
        $result = (new CanonicalJsonSpool())->canonicalize($projection, CanonicalJsonProfile::NONPROFIT, $output);
        rewind($output);
        $actual = stream_get_contents($output);
        fclose($output);

        $expected = '{"hash":"new","nested":{"keep":"změněno"},"rows":[{"amount_cents":50010,"id":"a"},{"amount_cents":70020,"id":"b"}],"z":3}';
        self::assertSame($expected, $actual);
        self::assertSame(hash('sha256', $expected), $result->sha256);
    }
}
