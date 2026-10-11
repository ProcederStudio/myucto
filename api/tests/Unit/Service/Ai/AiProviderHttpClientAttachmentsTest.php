<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Ai\AiProviderHttpClient;
use MyInvoice\Tests\Support\FakeAiProviderHttpClientFactory;
use PHPUnit\Framework\TestCase;

/** Binární přílohy (PDF, obrázky) v AiProviderHttpClient::completeJson() pro každého poskytovatele. */
final class AiProviderHttpClientAttachmentsTest extends TestCase
{
    private const PDF = "%PDF-1.4\nsyntetické PDF";

    /** @return list<array{name:string,mime:string,bytes:string}> */
    private static function attachments(): array
    {
        return [
            ['name' => 'zapis.pdf', 'mime' => 'application/pdf', 'bytes' => self::PDF],
            ['name' => 'doklad.png', 'mime' => 'image/png', 'bytes' => 'PNGBYTES'],
        ];
    }

    private static function anthropicOk(): Response
    {
        return new Response(200, [], json_encode([
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'tool_use', 'input' => ['answer' => 'ok']]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ]));
    }

    /** @param array<int, array<string,mixed>> $history @return array<string,mixed> */
    private static function sentJson(array $history, int $i = 0): array
    {
        return json_decode((string) $history[$i]['request']->getBody(), true);
    }

    public function testAnthropicSendsDocumentAndImageBlocksBeforeText(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [self::anthropicOk()], $history);

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 8000, self::attachments());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $content = self::sentJson($history)['messages'][0]['content'];
        self::assertSame('document', $content[0]['type']);
        self::assertSame('application/pdf', $content[0]['source']['media_type']);
        self::assertSame(base64_encode(self::PDF), $content[0]['source']['data']);
        self::assertSame('image', $content[1]['type']);
        self::assertSame('image/png', $content[1]['source']['media_type']);
        self::assertSame(['type' => 'text', 'text' => 'DOTAZ'], $content[2]);
        self::assertSame(8000, self::sentJson($history)['max_tokens'], 'strop 4000 už velký výstup neořezává');
        self::assertSame(AiProviderHttpClient::LONG_REQUEST_TIMEOUT, $history[0]['options']['timeout']);
    }

    /** Claude 5 vrací na `temperature` HTTP 400 (zjištěno ostrým voláním); starší modely ho dostávají dál. */
    public function testTemperatureIsSentOnlyToModelsThatAcceptIt(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [self::anthropicOk(), self::anthropicOk()], $history);

        $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], 'claude-haiku-4-5', 300);
        $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], AiProviderHttpClient::BANK_ESCALATION_MODEL, 300);

        self::assertSame(0, self::sentJson($history, 0)['temperature']);
        self::assertArrayNotHasKey('temperature', self::sentJson($history, 1));
        self::assertTrue(AiProviderHttpClient::anthropicAcceptsTemperature('claude-3-5-sonnet-20241022'));
        self::assertTrue(AiProviderHttpClient::anthropicAcceptsTemperature('claude-sonnet-4-5'));
        self::assertFalse(AiProviderHttpClient::anthropicAcceptsTemperature('claude-opus-5-5'));
    }

    /** Stávající volající (bez příloh) posílají požadavek beze změny. */
    public function testWithoutAttachmentsRequestIsUnchanged(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [self::anthropicOk()], $history);

        $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 300);

        $sent = self::sentJson($history);
        self::assertSame('DOTAZ', $sent['messages'][0]['content']);
        self::assertSame(300, $sent['max_tokens']);
        self::assertArrayNotHasKey('timeout', $history[0]['options']);
    }

    public function testOpenAiSendsFileAndImageUrlParts(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'openai', [
            new Response(200, [], json_encode([
                'model' => 'gpt-test',
                'choices' => [['message' => ['content' => '{"answer":"ok"}']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
            ])),
        ], $history, 'gpt-test');

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 2000, self::attachments());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(['answer' => 'ok'], $r['data']);
        $content = self::sentJson($history)['messages'][1]['content'];
        self::assertSame('file', $content[0]['type']);
        self::assertSame('zapis.pdf', $content[0]['file']['filename']);
        self::assertSame('data:application/pdf;base64,' . base64_encode(self::PDF), $content[0]['file']['file_data']);
        self::assertSame('image_url', $content[1]['type']);
        self::assertSame('data:image/png;base64,' . base64_encode('PNGBYTES'), $content[1]['image_url']['url']);
        self::assertSame(['type' => 'text', 'text' => 'DOTAZ'], $content[2]);
    }

    public function testGeminiSendsInlineDataParts(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'gemini', [
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => '{"answer":"ok"}']]]]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 1],
            ])),
        ], $history, 'gemini-2.5-flash');

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 2000, self::attachments());

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $parts = self::sentJson($history)['contents'][0]['parts'];
        self::assertSame(['mime_type' => 'application/pdf', 'data' => base64_encode(self::PDF)], $parts[0]['inline_data']);
        self::assertSame('image/png', $parts[1]['inline_data']['mime_type']);
        self::assertSame(['text' => 'DOTAZ'], $parts[2]);
    }

    public function testOllamaRejectsBinaryAttachmentsWithoutCallingProvider(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'ollama', [], $history, 'llama3');

        $r = $client->completeJson(1, 'SYS', 'DOTAZ', ['type' => 'object'], null, 2000, self::attachments());

        self::assertSame(['ok' => false, 'error' => 'attachments_unsupported'], $r);
        self::assertSame([], $history);
    }

    public function testStatusUsesStrongerModelForHaikuAndReportsMissingDpa(): void
    {
        $status = FakeAiProviderHttpClientFactory::create($this, 'anthropic', [])->status(1);
        self::assertSame([
            'available' => true, 'reason' => null, 'provider' => 'anthropic',
            'model' => AiProviderHttpClient::BANK_ESCALATION_MODEL, 'supports_files' => true,
        ], $status);

        $history = [];
        $noDpa = FakeAiProviderHttpClientFactory::create($this, 'openai', [], $history, 'gpt-test', false)->status(1);
        self::assertFalse($noDpa['available']);
        self::assertSame('dpa_not_confirmed', $noDpa['reason']);
        self::assertSame('gpt-test', $noDpa['model']);
    }
}
