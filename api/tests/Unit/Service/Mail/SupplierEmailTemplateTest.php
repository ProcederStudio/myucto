<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Repository\SupplierEmailTemplateRepository;
use MyInvoice\Service\Mail\EmailTemplateCatalog;
use MyInvoice\Service\Mail\EmailTemplateResolver;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RateLimit\MailRateLimiter;
use MyInvoice\Service\Mail\RateLimit\MailSendCounterInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class SupplierEmailTemplateTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;
    private EmailTemplateResolver $resolver;
    private Mailer $mailer;
    private EmailTemplateCatalog $catalog;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY, code TEXT, locale TEXT, subject TEXT, body_html TEXT, body_text TEXT, updated_at TEXT, updated_by INTEGER)');
        $this->pdo->exec('CREATE TABLE supplier_email_templates (id INTEGER PRIMARY KEY, supplier_id INTEGER, code TEXT, locale TEXT, subject TEXT, body_html TEXT, body_text TEXT, updated_at TEXT, updated_by INTEGER)');
        $this->db = $this->createStub(Connection::class);
        $this->db->method('pdo')->willReturn($this->pdo);
        $config = new Config(['app' => ['name' => 'MyÚčto.cz'], 'smtp' => ['from_email' => 'sender@example.test', 'from_name' => 'Synthetic', 'rate_limit' => ['enabled' => false, 'drain_on_cli' => false]]]);
        $global = new EmailTemplateRepository($this->db);
        $this->catalog = new EmailTemplateCatalog($config);
        $this->resolver = new EmailTemplateResolver($global, new SupplierEmailTemplateRepository($this->db), $this->catalog);
        $this->mailer = new Mailer($config, new NullLogger(), $this->db, $global);
    }

    public function testResolutionPrefersRequestedLanguageBeforeAnyCzechOverride(): void
    {
        $this->insert('supplier_email_templates', 'invoice_send', 'cs', 'Company A Czech', 1);
        $this->insert('email_templates', 'invoice_send', 'cs', 'Global Czech');
        self::assertSame('default', $this->resolver->resolve('invoice_send', 'en', 1)['source']);
        self::assertSame('en', $this->resolver->resolve('invoice_send', 'en', 1)['resolved_locale']);
        $this->insert('email_templates', 'invoice_send', 'en', 'Global English');
        self::assertSame('Global English', $this->resolver->resolve('invoice_send', 'en', 1)['subject']);
        $this->insert('supplier_email_templates', 'invoice_send', 'en', 'Company A English', 1);
        $this->insert('supplier_email_templates', 'invoice_send', 'en', 'Company B English', 2);
        self::assertSame('Company A English', $this->resolver->resolve('invoice_send', 'en', 1)['subject']);
        self::assertSame('Company B English', $this->resolver->resolve('invoice_send', 'en', 2)['subject']);
        self::assertSame('Global English', $this->resolver->resolve('invoice_send', 'en')['subject']);
        self::assertSame('Company A Czech', $this->resolver->resolve('invoice_send', 'de', 1)['subject']);
    }

    public function testResetOnlyDeletesCurrentSupplierCodeAndLocale(): void
    {
        $this->insert('supplier_email_templates', 'invoice_send', 'cs', 'A cs', 1);
        $this->insert('supplier_email_templates', 'invoice_send', 'en', 'A en', 1);
        $this->insert('supplier_email_templates', 'invoice_send', 'cs', 'B cs', 2);
        $this->insert('email_templates', 'invoice_send', 'cs', 'Global');
        (new SupplierEmailTemplateRepository($this->db))->delete(1, 'invoice_send', 'cs');
        self::assertSame('Global', $this->resolver->detail(1, 'invoice_send', 'cs')['subject']);
        self::assertFalse($this->resolver->detail(1, 'invoice_send', 'cs')['has_override']);
        self::assertSame('A en', $this->resolver->resolve('invoice_send', 'en', 1)['subject']);
        self::assertSame('B cs', $this->resolver->resolve('invoice_send', 'cs', 2)['subject']);
    }

    public function testActualSendingRequiresExplicitContextAndPreservesSubjectPrecedence(): void
    {
        $this->insert('supplier_email_templates', 'invoice_send', 'cs', 'Company {{ invoice.varsymbol }}', 1);
        $transport = new SupplierTemplateCapturingTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($this->mailer, $transport);
        $vars = ['supplier' => ['id' => 1, 'company_name' => 'Synthetic'], 'invoice' => ['varsymbol' => 'DEMO'], 'subject' => 'File subject'];
        $this->mailer->sendTemplate('invoice_send', 'cs', ['recipient@example.test'], $vars);
        self::assertSame('File subject', $transport->messages[0]->getSubject());
        $this->mailer->sendTemplate('invoice_send', 'cs', ['recipient@example.test'], $vars, templateSupplierId: 1);
        self::assertSame('Company DEMO', $transport->messages[1]->getSubject());
        $this->mailer->sendTemplate('invoice_send', 'cs', ['recipient@example.test'], $vars + ['client_subject' => 'Literal & {{name}}'], templateSupplierId: 1);
        self::assertSame('Literal & {{name}}', $transport->messages[2]->getSubject());
        $this->mailer->sendTemplate('invoice_send', 'cs', ['recipient@example.test'], $vars + ['client_subject' => 'Client'], 'One-off {{ invoice.varsymbol }}', templateSupplierId: 1);
        self::assertSame('One-off DEMO', $transport->messages[3]->getSubject());
    }

    public function testBatchQueueReplayRetainsExplicitCompanyAndUsesLatestTemplate(): void
    {
        $this->pdo->exec('CREATE TABLE mail_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, not_before TEXT, status TEXT, attempts INTEGER, template TEXT, locale TEXT, recipients INTEGER, defer_reason TEXT, payload TEXT, sent_at TEXT, last_error TEXT)');
        $this->insert('supplier_email_templates', 'invoice_send', 'cs', 'Company A before', 1);
        $this->insert('supplier_email_templates', 'invoice_send', 'cs', 'Company B', 2);
        $blocked = true;
        $counter = $this->createStub(MailSendCounterInterface::class);
        $counter->method('sentSince')->willReturnCallback(static function () use (&$blocked): int { return $blocked ? 1 : 0; });
        $counter->method('oldestSince')->willReturn(new \DateTimeImmutable('-30 minutes'));
        $config = new Config(['smtp' => ['from_email' => 'sender@example.test', 'from_name' => 'Synthetic', 'rate_limit' => ['enabled' => true, 'per_hour' => 1, 'per_day' => 100, 'max_recipients_per_message' => 1, 'drain_on_cli' => false]]]);
        $mailer = new Mailer($config, new NullLogger(), $this->db, new EmailTemplateRepository($this->db), rateLimiter: new MailRateLimiter($config, $counter, new NullLogger()));
        $transport = new SupplierTemplateCapturingTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);
        $result = $mailer->sendTemplateDetailed('invoice_send', 'cs', ['one@example.test', 'two@example.test', 'three@example.test'], ['supplier' => ['id' => 2, 'company_name' => 'Branding B']], templateSupplierId: 1);
        self::assertTrue($result['deferred']);
        self::assertSame(3, $result['batches']);
        self::assertCount(0, $transport->messages);
        foreach ($this->pdo->query('SELECT payload FROM mail_outbox')->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            self::assertSame(1, json_decode($payload, true)['template_supplier_id']);
        }
        $this->pdo->exec("UPDATE supplier_email_templates SET subject = 'Company A latest' WHERE supplier_id = 1");
        $blocked = false;
        $report = $mailer->flushDeferred(new \DateTimeImmutable('+2 hours'));
        self::assertSame(['sent' => 3, 'deferred' => 0, 'failed' => 0], $report);
        self::assertCount(3, $transport->messages);
        foreach ($transport->messages as $message) self::assertSame('Company A latest', $message->getSubject());
    }

    public function testSystemTemplatesNeverUseSupplierRowsEvenWithExplicitContext(): void
    {
        $this->insert('supplier_email_templates', 'password_reset', 'cs', 'Disallowed company password', 1);
        self::assertSame('default', $this->resolver->resolve('password_reset', 'cs', 1)['source']);
        self::assertFalse($this->catalog->isSupplierTemplate('password_reset', 'cs'));
        self::assertFalse($this->catalog->isSupplierTemplate('payroll_document_secure_link', 'cs'));
    }

    public function testAllInheritedTemplatesValidateAndPreviewWithoutRealCompanyData(): void
    {
        foreach (EmailTemplateCatalog::SUPPLIER_CODES as $code) {
            foreach (EmailTemplateCatalog::LOCALES as $locale) {
                $template = $this->catalog->defaults($code, $locale);
                self::assertNotNull($template, "$code.$locale");
                self::assertNull($this->mailer->validateSupplierTemplate($code, $locale, $template), "$code.$locale");
                self::assertNotEmpty($this->mailer->previewUserTemplate($code, $locale, $template)['body_html']);
            }
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM supplier_email_templates')->fetchColumn());
    }

    public function testDynamicEditorSubjectsAndLegacyRuntimeFallbackStayDistinct(): void
    {
        foreach ([
            'invoice_approval' => ['Žádost o schválení výkazu práce ({{ invoice.varsymbol_or_id }})', 'Žádost o schválení výkazu práce (20260001)'],
            'purchase_invoice_approval' => ["{{ is_reminder ? 'Připomínka: ' : '' }}Doklad ke schválení: {{ vendor_name }} {{ document_number }}", 'Doklad ke schválení: Ukázkový dodavatel DEMO-2026-001'],
            'document_request_reminder' => ['Chybí doklad — {{ description }}', 'Chybí doklad — Ukázkový chybějící doklad'],
        ] as $code => [$editable, $rendered]) {
            $template = $this->catalog->defaults($code, 'cs');
            self::assertSame($editable, $template['subject']);
            self::assertSame($rendered, $this->mailer->previewUserTemplate($code, 'cs', $template)['subject']);
            self::assertSame('MyÚčto.cz', $this->catalog->fallbackSubject($code, 'cs'));
        }
        self::assertSame('Faktura {{ invoice.varsymbol }}', $this->catalog->defaults('invoice_send', 'cs')['subject']);
        $transport = new SupplierTemplateCapturingTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($this->mailer, $transport);
        $this->mailer->sendTemplate('invoice_send', 'cs', ['recipient@example.test'], ['supplier' => ['id' => 1, 'company_name' => 'Synthetic']]);
        self::assertSame('Faktura — MyÚčto.cz', $transport->messages[0]->getSubject());
    }

    public function testInvalidSubjectSandboxAndDeadFunctionalTokensAreRejected(): void
    {
        $valid = ['subject' => 'Example', 'body_html' => '<a href="{{ approval_url }}">Approve</a>', 'body_text' => '{{ approval_url }}'];
        self::assertNull($this->mailer->validateSupplierTemplate('invoice_approval', 'cs', $valid));
        foreach (["Bad\r\nBcc: test@example.test", '{% include "_layout.html.twig" %}'] as $subject) {
            self::assertSame('subject', $this->mailer->validateSupplierTemplate('invoice_approval', 'cs', array_replace($valid, ['subject' => $subject]))['field']);
        }
        foreach (['<!-- {{ approval_url }} -->', '{% if false %}<a href="{{ approval_url }}">Approve</a>{% endif %}', '<p>{{ approval_url }}</p>', '<a data-href="{{ approval_url }}">Approve</a>', '<a href="{{ approval_url }}"><span hidden>Approve</span></a>', '<a href="{{ approval_url }}"></a>', '<div hidden><a href="{{ approval_url }}">Approve</a></div>', '<a style="display: none" href="{{ approval_url }}">Approve</a>', '<a href="https://preview.example.test/approve/synthetic-token">Hardcoded</a>'] as $html) {
            self::assertSame('body_html', $this->mailer->validateSupplierTemplate('invoice_approval', 'cs', array_replace($valid, ['body_html' => $html]))['field']);
        }
        $code = ['subject' => 'Code', 'body_html' => '<b>{{ code }}</b>', 'body_text' => '{{ code }}'];
        self::assertNull($this->mailer->validateSupplierTemplate('work_report_access_code', 'en', $code));
        self::assertSame('body_html', $this->mailer->validateSupplierTemplate('work_report_access_code', 'en', array_replace($code, ['body_html' => '<p>583194</p>']))['field']);
        self::assertSame('body_text', $this->mailer->validateSupplierTemplate('work_report_access_code', 'en', array_replace($code, ['body_text' => '{% if false %}{{ code }}{% endif %}']))['field']);
    }

    private function insert(string $table, string $code, string $locale, string $subject, ?int $supplierId = null): void
    {
        $columns = 'code, locale, subject, body_html, body_text, updated_at';
        $values = [$code, $locale, $subject, '<p>Company body {{ invoice.varsymbol }}</p>', 'Company body {{ invoice.varsymbol }}', '2026-01-15 12:00:00'];
        if ($supplierId !== null) { $columns .= ', supplier_id'; $values[] = $supplierId; }
        $stmt = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
        $stmt->execute($values);
    }
}

final class SupplierTemplateCapturingTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $messages = [];
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->messages[] = $message;
        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }
    public function __toString(): string { return 'synthetic://test'; }
}
