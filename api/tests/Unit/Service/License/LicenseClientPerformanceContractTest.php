<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\License;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\License\LicenseClient;
use PHPUnit\Framework\TestCase;

final class LicenseClientPerformanceContractTest extends TestCase
{
    public function testQuoteAndChangeHitContractEndpoints(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"ok":true,"quote_token":"quote"}'),
            new Response(200, [], '{"ok":true,"enabled":false,"pending_disable":true}'),
        ]);
        $client = new LicenseClient(new Config([]), http: new Client(['handler' => HandlerStack::create($mock)]));

        $quote = $client->performanceQuote('key', 'iid', true);
        $quoteRequest = $mock->getLastRequest();
        self::assertSame('api/license/performance/quote', $quoteRequest?->getUri()->getPath());
        self::assertSame(
            ['license_key' => 'key', 'instance_id' => 'iid', 'enabled' => true],
            json_decode((string) $quoteRequest?->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('quote', $quote['quote_token']);

        $change = $client->performanceChange('key', 'iid', false, 'quote');
        $changeRequest = $mock->getLastRequest();
        self::assertSame('api/license/performance', $changeRequest?->getUri()->getPath());
        self::assertSame(
            ['license_key' => 'key', 'instance_id' => 'iid', 'enabled' => false, 'quote_token' => 'quote'],
            json_decode((string) $changeRequest?->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertTrue($change['pending_disable']);
    }
}
