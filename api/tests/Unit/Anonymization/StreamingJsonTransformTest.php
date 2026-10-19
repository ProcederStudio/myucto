<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Service\Anonymization\NonprofitArchiveJsonScalarTransformer;
use MyInvoice\Service\Anonymization\NonprofitDppoCloneScrubber;
use MyInvoice\Service\Anonymization\FinancialCloneHashResolver;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonProfile;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Anonymization\Streaming\StreamingJsonTransform;
use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PHPUnit\Framework\TestCase;

final class StreamingJsonTransformTest extends TestCase
{
    public function testStreamingTransformMatchesCallableLegacySsotAcrossGrowingRows(): void
    {
        $oldHash = hash('sha256', 'source-proof');
        $newHash = hash('sha256', 'target-proof');
        $rows = [];
        for ($index = 0; $index < 1001; $index++) {
            $rows[] = [
                'ordinal' => $index,
                'company_name' => 'Synthetic Original ' . $index . ' s.r.o.',
                'email' => 'person' . $index . '@example.test',
                'note' => 'Kontakt person' . $index . '@example.test',
                'amount_cents' => '50010',
                'date' => '2099-12-31',
                'status' => 'final',
                'source_sha256' => $oldHash,
            ];
        }
        $source = ['entity_proof' => ['supplier' => ['company_name' => 'Synthetic Original Root s.r.o.']], 'rows' => $rows];
        $scrubber = new TextScrubber(new Pseudonymizer(hash('sha256', 'stream-transform-secret', true)));
        $hashes = [$oldHash => $newHash];
        $expected = NonprofitTaxRules::canonicalJson(NonprofitDppoCloneScrubber::transform($scrubber, $source, $hashes));
        $json = json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $transformer = new NonprofitArchiveJsonScalarTransformer(
            $scrubber,
            new readonly class ($hashes) implements FinancialCloneHashResolver {
                /** @param array<string,string> $hashes */
                public function __construct(private array $hashes) {}

                public function resolve(string $oldHash): ?string
                {
                    return $this->hashes[$oldHash] ?? null;
                }
            },
        );
        $transformed = (new StreamingJsonTransform())->transform($this->chunks($json, 37), $transformer, CanonicalJsonProfile::NONPROFIT);
        $output = fopen('php://temp', 'w+b');
        self::assertIsResource($output);

        $result = (new CanonicalJsonSpool())->canonicalize($transformed, CanonicalJsonProfile::NONPROFIT, $output);
        rewind($output);
        $actual = stream_get_contents($output);
        fclose($output);

        self::assertSame($expected, $actual);
        self::assertSame(hash('sha256', $expected), $result->sha256);
        self::assertStringNotContainsString('Synthetic Original', $actual);
        self::assertStringNotContainsString('@example.test', $actual);
        self::assertStringContainsString('"amount_cents":"50010"', $actual);
        self::assertStringContainsString('"date":"2099-12-31"', $actual);
        self::assertStringContainsString('"source_sha256":"' . $newHash . '"', $actual);
    }

    /** @return iterable<string> */
    private function chunks(string $json, int $size): iterable
    {
        for ($offset = 0, $length = strlen($json); $offset < $length; $offset += $size) {
            yield substr($json, $offset, $size);
        }
    }
}
