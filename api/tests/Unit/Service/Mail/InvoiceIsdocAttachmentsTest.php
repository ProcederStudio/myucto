<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Mail\InvoiceIsdocAttachments;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RateLimit\MailRateLimiter;
use MyInvoice\Service\Mail\RateLimit\MailSendCounterInterface;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use MyInvoice\Tests\Support\StandaloneIsdocTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class InvoiceIsdocAttachmentsTest extends TestCase
{
    use StandaloneIsdocTestTrait;

    protected function setUp(): void { $this->setupIsdocDatabase(); }

    public static function templates(): iterable
    {
        foreach (['invoice_send', 'invoice_reminder', 'proforma_reminder', 'invoice_payment_thanks'] as $code) {
            yield $code => [$code, true];
        }
        yield 'unrelated mail' => ['purchase_approval', false];
    }

    #[DataProvider('templates')]
    public function testOnlyInvoiceTemplatesAttachSchemaValidIsdoc(string $code, bool $expected): void
    {
        $this->pdo->exec('UPDATE supplier SET email_attach_isdoc = 1 WHERE id = 41');
        $attachments = $this->attachments()->forEmail($code, ['invoice' => $this->isdocInvoice()], $this->pdf());
        self::assertCount($expected ? 2 : 1, $attachments);
        if ($expected) {
            self::assertSame('application/x-isdoc', $attachments[1]['contentType']);
            self::assertSame('Faktura-TEST-2026-91.isdoc', $attachments[1]['name']);
            self::assertArrayNotHasKey('path', $attachments[1]);
            $this->assertIsdocSchema($attachments[1]['content']);
        }
    }

    public function testNoIsdocWhenSupplierDisabledOrPdfMissingOrInvoiceIsDraft(): void
    {
        $builder = $this->attachments();
        self::assertSame($this->pdf(), $builder->forEmail('invoice_send', ['invoice' => $this->isdocInvoice()], $this->pdf()));
        $this->pdo->exec('UPDATE supplier SET email_attach_isdoc = 1 WHERE id = 41');
        self::assertSame([], $builder->forEmail('invoice_payment_thanks', ['invoice' => $this->isdocInvoice()], []));
        self::assertSame($this->pdf(), $builder->forEmail('invoice_send', [], $this->pdf()));
        foreach (['draft', 'cancelled'] as $status) {
            self::assertSame($this->pdf(), $builder->forEmail('invoice_send', ['invoice' => $this->isdocInvoice(['status' => $status])], $this->pdf()));
        }
    }

    public function testRetryKeepsOriginalSignedContentAndDoesNotDuplicateAttachment(): void
    {
        $this->pdo->exec('UPDATE supplier SET email_attach_isdoc = 1 WHERE id = 41');
        $signing = $this->createMock(PdfSigningService::class);
        $signing->expects(self::once())->method('signIsdocIfEnabled')->willReturn('original signed XML');
        $builder = $this->attachments($signing);
        $first = $builder->forEmail('invoice_send', ['invoice' => $this->isdocInvoice()], $this->pdf());
        $queued = json_decode(json_encode($first, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->pdo->exec('UPDATE supplier SET email_attach_isdoc = 0 WHERE id = 41');
        self::assertSame($first, $builder->forEmail('invoice_send', ['invoice' => $this->isdocInvoice()], $queued));
        self::assertSame('original signed XML', $queued[1]['content']);
    }

    public function testMailerSendsIsdocAsSeparateMimeAttachment(): void
    {
        $this->pdo->exec('UPDATE supplier SET email_attach_isdoc = 1 WHERE id = 41');
        $mailer = $this->mailer();
        $transport = $this->captureTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);
        $mailer->sendTemplate('invoice_send', 'cs', ['client@example.test'], [
            'invoice' => $this->isdocInvoice(), 'supplier' => ['id' => 41],
        ], attachments: $this->pdf());
        self::assertInstanceOf(Email::class, $transport->message);
        $parts = $transport->message->getAttachments();
        self::assertCount(2, $parts);
        self::assertSame('invoice.pdf', $parts[0]->getFilename());
        self::assertSame('Faktura-TEST-2026-91.isdoc', $parts[1]->getFilename());
        self::assertSame('application', $parts[1]->getMediaType());
        self::assertSame('x-isdoc', $parts[1]->getMediaSubtype());
        $this->assertIsdocSchema($parts[1]->getBody());
    }

    public function testDeferredMailContainsXmlInDurableQueuePayload(): void
    {
        $this->pdo->exec('UPDATE supplier SET email_attach_isdoc = 1 WHERE id = 41');
        $this->pdo->exec('CREATE TABLE mail_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, not_before TEXT, status TEXT, attempts INTEGER, template TEXT, locale TEXT, recipients INTEGER, defer_reason TEXT, payload TEXT)');
        $result = $this->mailer(defer: true)->sendTemplateDetailed('invoice_reminder', 'cs', ['client@example.test'], [
            'invoice' => $this->isdocInvoice(), 'supplier' => ['id' => 41],
        ], attachments: $this->pdf());
        self::assertTrue($result['deferred']);
        $payload = json_decode($this->pdo->query('SELECT payload FROM mail_outbox')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $payload['attachments']);
        self::assertArrayNotHasKey('path', $payload['attachments'][1]);
        $this->assertIsdocSchema($payload['attachments'][1]['content']);
    }

    public static function optionalIsdocFailures(): iterable
    {
        foreach (['invoice_send', 'invoice_reminder', 'proforma_reminder', 'invoice_payment_thanks'] as $code) {
            foreach (['exchange_rate', 'signing'] as $failure) {
                yield $code . ' / ' . $failure => [$code, $failure];
            }
        }
    }

    #[DataProvider('optionalIsdocFailures')]
    public function testMailerStillSendsPdfAndOtherAttachmentsWhenOptionalIsdocFails(string $code, string $failure): void
    {
        $this->pdo->exec('UPDATE supplier SET email_attach_isdoc = 1 WHERE id = 41');
        $invoice = $this->isdocInvoice();
        if ($failure === 'exchange_rate') {
            $invoice['currency'] = 'EUR';
            $rates = $this->createMock(ExchangeRateApplier::class);
            $rates->expects(self::once())->method('ensureRate')->with(91, true)
                ->willThrowException(new \RuntimeException('synthetic private exchange-rate diagnostic'));
            $service = $this->isdocService(rates: $rates);
        } else {
            $signing = $this->createMock(PdfSigningService::class);
            $signing->expects(self::once())->method('signIsdocIfEnabled')
                ->willThrowException(new \RuntimeException('synthetic private signing diagnostic'));
            $service = $this->isdocService(signing: $signing);
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('mail.invoice_isdoc_attachment_failed', [
            'template' => $code,
            'invoice_id' => 91,
            'supplier_id' => 41,
            'exception_class' => \RuntimeException::class,
        ]);
        $mailer = $this->mailer(invoiceIsdoc: new InvoiceIsdocAttachments($this->delivery, $service, $logger));
        $transport = $this->captureTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);
        $attachments = [...$this->pdf(), [
            'content' => 'Synthetic supplementary document', 'name' => 'appendix.txt', 'contentType' => 'text/plain',
        ]];
        $result = $mailer->sendTemplateDetailed($code, 'cs', ['client@example.test'], [
            'invoice' => $invoice, 'supplier' => ['id' => 41],
        ], attachments: $attachments);

        self::assertArrayHasKey('smtp_response', $result);
        self::assertInstanceOf(Email::class, $transport->message);
        $parts = $transport->message->getAttachments();
        self::assertCount(2, $parts);
        foreach ($attachments as $index => $attachment) {
            self::assertSame($attachment['name'], $parts[$index]->getFilename());
            self::assertSame($attachment['content'], $parts[$index]->getBody());
            self::assertSame($attachment['contentType'], $parts[$index]->getMediaType() . '/' . $parts[$index]->getMediaSubtype());
        }
    }

    private function captureTransport(): TransportInterface
    {
        return new class implements TransportInterface {
            public ?Email $message = null;
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->message = $message instanceof Email ? $message : null;
                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }
            public function __toString(): string { return 'capture://'; }
        };
    }

    private function attachments(?PdfSigningService $signing = null): InvoiceIsdocAttachments
    {
        return new InvoiceIsdocAttachments($this->delivery, $this->isdocService(signing: $signing), new NullLogger());
    }

    private function pdf(): array
    {
        return [['content' => '%PDF-test', 'name' => 'invoice.pdf', 'contentType' => 'application/pdf']];
    }

    private function mailer(bool $defer = false, ?InvoiceIsdocAttachments $invoiceIsdoc = null): Mailer
    {
        $config = new Config(['smtp' => [
            'from_email' => 'sender@example.test', 'from_name' => 'Test',
            'rate_limit' => ['enabled' => $defer, 'drain_on_cli' => false, 'per_hour' => 1],
        ]]);
        $counter = $this->createStub(MailSendCounterInterface::class);
        $counter->method('sentSince')->willReturn($defer ? 1 : 0);
        $counter->method('oldestSince')->willReturn(new \DateTimeImmutable());
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn(['subject' => 'Test', 'body_html' => '<p>Test</p>', 'body_text' => 'Test']);
        return new Mailer($config, new NullLogger(), $this->db, $templates,
            rateLimiter: new MailRateLimiter($config, $counter, new NullLogger()),
            invoiceIsdoc: $invoiceIsdoc ?? $this->attachments());
    }
}
