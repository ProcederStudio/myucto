<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\RecurringDraftReminder;
use MyInvoice\Service\Mail\Mailer;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurringDraftTemplateContextTest extends TestCase
{
    public function testAutomaticRemindersPassTheirOwnCompanyToTemplateSelection(): void
    {
        $pdo = $this->database();
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturnCallback(static fn (int $id): array => [
            'id' => $id, 'supplier_id' => $id === 101 ? 11 : 22,
            'status' => 'draft', 'issue_date' => '2026-10-01',
            'client_company_name' => 'Synthetic Customer', 'amount_to_pay' => 1200,
        ]);
        $sent = [];
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::exactly(2))->method('sendTemplate')->willReturnCallback(
            static function (...$args) use (&$sent): string {
                $sent[] = $args;
                return 'Synthetic SMTP acceptance';
            },
        );
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::exactly(2))->method('log')->with(
            'recurring.reminder_sent', 7, 'recurring_template', self::anything(),
            self::callback(static fn (array $data): bool => in_array($data['invoice_id'], [101, 202], true)),
            '', 'cron',
        );
        $service = new RecurringDraftReminder($invoices, $db, $mailer, new Config(['app' => ['url' => 'https://app.example.test']]), $logger);

        self::assertTrue($service->send($this->template(11), 101));
        self::assertTrue($service->send($this->template(22), 202));

        foreach ([[11, 101, 'company-a@example.test', 2], [22, 202, 'company-b@example.test', 1]] as $index => [$supplierId, $invoiceId, $recipient, $count]) {
            $args = $sent[$index];
            self::assertSame('recurring_draft_reminder', $args[0]);
            self::assertSame('cs', $args[1]);
            self::assertSame([$recipient], $args[2]);
            self::assertSame($invoiceId, $args[3]['invoice']['id']);
            self::assertSame($supplierId, $args[3]['supplier']['id']);
            self::assertSame($count, $args[3]['work_item_count']);
            self::assertSame("https://app.example.test/invoices/{$invoiceId}", $args[3]['edit_link']);
            // Branding vars alone cannot select an override: the trusted explicit argument is required.
            self::assertSame($supplierId, $args[10]);
        }
    }

    #[DataProvider('unavailableDrafts')]
    public function testMissingOrIssuedDraftDoesNotSendOrLoadCompanyData(?array $invoice): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplate');
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::never())->method('log');
        $service = new RecurringDraftReminder($invoices, $db, $mailer, new Config([]), $logger);

        self::assertFalse($service->send($this->template(11), 101));
    }

    public static function unavailableDrafts(): iterable
    {
        yield 'deleted draft' => [null];
        yield 'already issued invoice' => [['id' => 101, 'status' => 'issued']];
    }

    public function testCompanyWithoutValidRecipientDoesNotSend(): void
    {
        $pdo = $this->database();
        $pdo->exec("UPDATE supplier SET email = '' WHERE id = 11");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturn(['id' => 101, 'status' => 'draft']);
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplate');
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::never())->method('log');
        $service = new RecurringDraftReminder($invoices, $db, $mailer, new Config([]), $logger);

        self::assertFalse($service->send($this->template(11), 101));
    }

    private function template(int $supplierId): array
    {
        return ['id' => $supplierId + 300, 'supplier_id' => $supplierId, 'created_by' => 7, 'name' => 'Synthetic recurring template'];
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, company_name TEXT, display_name TEXT, tagline TEXT, street TEXT, city TEXT, zip TEXT, email TEXT, phone TEXT, web TEXT, email_branding_enabled INTEGER, email_accent_color TEXT, logo_path TEXT, branding_profiles_enabled INTEGER DEFAULT 0, default_branding_profile_id INTEGER, country_id INTEGER)');
        $pdo->exec('CREATE TABLE branding_profiles (id INTEGER PRIMARY KEY, supplier_id INTEGER, display_name TEXT, tagline TEXT, email TEXT, phone TEXT, web TEXT, branding_enabled INTEGER, accent_color TEXT, logo_path TEXT, is_active INTEGER)');
        $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, name_cs TEXT)');
        $pdo->exec('CREATE TABLE work_reports (id INTEGER PRIMARY KEY, invoice_id INTEGER)');
        $pdo->exec('CREATE TABLE work_report_items (id INTEGER PRIMARY KEY, work_report_id INTEGER)');
        $pdo->exec("INSERT INTO supplier (id, company_name, email) VALUES (11, 'Synthetic Company A', 'company-a@example.test'), (22, 'Synthetic Company B', 'company-b@example.test')");
        $pdo->exec('INSERT INTO work_reports (id, invoice_id) VALUES (1, 101), (2, 202)');
        $pdo->exec('INSERT INTO work_report_items (id, work_report_id) VALUES (1, 1), (2, 1), (3, 2)');
        return $pdo;
    }
}
