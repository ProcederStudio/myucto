<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Service\Anonymization\LegacyStoredProofStreamAdapter;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof;
use MyInvoice\Tests\Unit\Tax\Nonprofit\NonprofitDppoStoredProofRebaseTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyStoredProofStreamAdapterTest extends TestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) if (is_file($path)) unlink($path);
    }

    public static function profiles(): array
    {
        return [['v3'], ['raw_v3'], ['v2'], ['legacy']];
    }

    #[DataProvider('profiles')]
    public function testStreamProjectionHasCallableRebaseParity(string $profile): void
    {
        [, $record] = (new \ReflectionMethod(NonprofitDppoStoredProofRebaseTest::class, 'fixture'))
            ->invoke(new NonprofitDppoStoredProofRebaseTest('testHistoricalRebasePreservesVersionHashModeAndFinancialProjection'), $profile);
        $source = $this->write($record);
        $sourceReceipt = (new LegacyStoredProofStreamAdapter($source))->verify();

        $entity = $record['entity_proof'];
        $entity['supplier']['company_name'] = 'Synthetic Stream Target s.r.o.';
        $rebased = NonprofitDppoStoredProof::rebaseHistorical($record, $record['data'], $record['inputs'], $entity);
        $target = $this->write($rebased);
        $targetReceipt = (new LegacyStoredProofStreamAdapter($target))->assertRebaseParity($sourceReceipt);

        self::assertSame($profile === 'raw_v3' ? 'v3' : $profile, $targetReceipt->profile);
        self::assertSame($sourceReceipt->financialIdentity(), $targetReceipt->financialIdentity());
        self::assertSame($sourceReceipt->rowsHash, $targetReceipt->rowsHash);
    }

    public function testStreamProjectionRejectsMutatedBridgeAmount(): void
    {
        [, $record] = (new \ReflectionMethod(NonprofitDppoStoredProofRebaseTest::class, 'fixture'))
            ->invoke(new NonprofitDppoStoredProofRebaseTest('testHistoricalRebasePreservesVersionHashModeAndFinancialProjection'), 'v3');
        $record['data']['nonprofit']['bridge']['source_lines'][0]['amount_cents']++;
        $path = $this->write($record);

        $this->expectException(\UnexpectedValueException::class);
        (new LegacyStoredProofStreamAdapter($path))->verify();
    }

    private function write(array $record): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-legacy-proof-test-' . bin2hex(random_bytes(12)) . '.json';
        file_put_contents($path, json_encode(['podklady' => ['nonprofit_calculation' => $record]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->paths[] = $path;
        return $path;
    }
}
