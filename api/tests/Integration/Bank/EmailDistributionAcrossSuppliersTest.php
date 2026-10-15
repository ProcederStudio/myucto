<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailAttachment;
use MyInvoice\Service\Bank\EmailNotice\EmailDistributionScope;
use MyInvoice\Service\Bank\EmailNotice\EmailPdfInvoiceIngestor;
use MyInvoice\Service\Import\DocumentAddresseeResolver;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionTransferService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Jedna schránka pro více firem a přesun příchozího dokladu.
 *
 * Hlídá hranici tenantů: doklad odejde jen do firmy, do které uživatel, který
 * rozdělování zapnul, smí zapisovat příchozí doklady, a přesun ho ve zdrojové
 * firmě opravdu vyřadí.
 */
#[Group('integration')]
final class EmailDistributionAcrossSuppliersTest extends TestCase
{
    private ContainerInterface $container;
    private PDO $pdo;
    private int $userId;
    private int $inboxRole;
    private int $mailbox;
    private int $other;
    private string $otherIc;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        $this->container = Bootstrap::buildContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();

        $this->inboxRole = $this->role(['documents.inbox', 'bank', 'bank.import']);
        $this->pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
                             VALUES (?, 'disabled-test-password', 'Synthetic distribution user', ?, 'cs', 1)")
            ->execute(['distribution-' . bin2hex(random_bytes(6)) . '@example.invalid', $this->inboxRole]);
        $this->userId = (int) $this->pdo->lastInsertId();

        $this->mailbox = $this->supplier('Synthetic Mailbox Holding', '99' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT));
        $this->otherIc = '98' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->other = $this->supplier('Synthetic Client Firm', $this->otherIc);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testInvoiceAddressedToOtherCompanyLandsInItsInbox(): void
    {
        $this->assign($this->mailbox, null);
        $this->assign($this->other, null);

        $summary = $this->ingest($this->pdf('FAKTURA - DANOVY DOKLAD c. 2026101 Odberatel ICO: ' . $this->otherIc));

        self::assertSame(1, $summary['imported'], (string) json_encode($summary['details']));
        self::assertNotNull($this->submissionIn($this->other), 'Doklad musí skončit ve firmě odběratele.');
        self::assertNull($this->submissionIn($this->mailbox));
        $log = $this->pdo->prepare('SELECT target_supplier_id FROM bank_email_attachment_ingests WHERE supplier_id = ?');
        $log->execute([$this->mailbox]);
        self::assertSame($this->other, (int) $log->fetchColumn(), 'Log schránky ukáže, kam doklad odešel.');
    }

    public function testReceiptWithoutIdentityStaysInMailboxCompany(): void
    {
        $this->assign($this->mailbox, null);
        $this->assign($this->other, null);

        // Bez rozdělování by tohle PDF filtr zahodil: nemá IČO ani název firmy.
        $summary = $this->ingest($this->pdf('Uctenka c. 55 Cerpaci stanice Nafta 1 250 Kc'));

        self::assertSame(1, $summary['imported'], (string) json_encode($summary['details']));
        self::assertNotNull($this->submissionIn($this->mailbox));
        self::assertNull($this->submissionIn($this->other));
    }

    public function testCompanyWithoutInboxPermissionIsNeverATarget(): void
    {
        $this->assign($this->mailbox, null);
        $this->assign($this->other, $this->role(['bank', 'bank.import']));

        $scope = $this->scope();
        self::assertSame([$this->mailbox], $scope->inboxSupplierIds);
        self::assertContains($this->other, $scope->bankSupplierIds);

        $this->ingest($this->pdf('FAKTURA c. 2026102 Odberatel ICO: ' . $this->otherIc), $scope);
        self::assertNull($this->submissionIn($this->other), 'Bez práva na příchozí doklady do firmy nesmí nic odejít.');
        self::assertNotNull($this->submissionIn($this->mailbox));
    }

    public function testDeactivatedUserReachesNoCompany(): void
    {
        $this->assign($this->mailbox, null);
        $this->assign($this->other, null);
        $this->pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$this->userId]);

        self::assertSame([], $this->container->get(CrossSupplierAuthorization::class)
            ->suppliersAllowingForUser($this->userId, ['documents.inbox']));
    }

    public function testMoveCreatesSubmissionInTargetAndRetiresSource(): void
    {
        $created = $this->container->get(PurchaseInvoiceSubmissionUploadService::class)
            ->submitBytes($this->pdf('Doklad pro presun ' . bin2hex(random_bytes(4))), 'presun.pdf', $this->mailbox, null, 'email');
        $sourceId = (int) $created['submission']['id'];

        $result = $this->container->get(PurchaseInvoiceSubmissionTransferService::class)
            ->move($sourceId, $this->mailbox, $this->other, $this->userId, null, 'phpunit');

        self::assertFalse($result['duplicate']);
        self::assertNull($this->submissionIn($this->mailbox), 'Ve zdrojové firmě podání zmizí.');
        $moved = $this->submissionIn($this->other);
        self::assertNotNull($moved);
        self::assertSame('email', (string) $moved['submitted_via']);
        $doc = $this->pdo->prepare('SELECT supplier_id, deleted_at FROM documents WHERE id = ?');
        $doc->execute([$result['source_document_id']]);
        $source = $doc->fetch(PDO::FETCH_ASSOC);
        self::assertSame($this->mailbox, (int) $source['supplier_id']);
        self::assertNotNull($source['deleted_at'], 'Originál ve zdrojové firmě jde do koše.');
    }

    public function testPortalSubmissionCannotBeMoved(): void
    {
        $created = $this->container->get(PurchaseInvoiceSubmissionUploadService::class)
            ->submitBytes($this->pdf('Portal ' . bin2hex(random_bytes(4))), 'portal.pdf', $this->mailbox, null, 'portal');

        $this->expectException(PurchaseInvoiceSubmissionException::class);
        $this->container->get(PurchaseInvoiceSubmissionTransferService::class)
            ->move((int) $created['submission']['id'], $this->mailbox, $this->other, $this->userId, null, 'phpunit');
    }

    public function testDetectedBuyerPointsToTheMatchingCompany(): void
    {
        $resolver = $this->container->get(DocumentAddresseeResolver::class);

        self::assertSame($this->other, $resolver->byBuyer($this->otherIc, null, [$this->mailbox, $this->other])['supplier_id']);
        self::assertNull($resolver->byBuyer('11223344', null, [$this->mailbox, $this->other])['supplier_id']);
        // Firma mimo kandidáty se nenavrhne, ani když IČO sedí.
        self::assertNull($resolver->byBuyer($this->otherIc, null, [$this->mailbox])['supplier_id']);
    }

    private function scope(): EmailDistributionScope
    {
        $auth = $this->container->get(CrossSupplierAuthorization::class);
        return new EmailDistributionScope(
            $this->mailbox,
            $auth->suppliersAllowingForUser($this->userId, ['bank.import']),
            $auth->suppliersAllowingForUser($this->userId, ['documents.inbox']),
        );
    }

    /** @return array<string,mixed> */
    private function ingest(string $content, ?EmailDistributionScope $scope = null): array
    {
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<' . bin2hex(random_bytes(8)) . '@example.invalid>',
            date: new \DateTimeImmutable('2026-01-15 10:00:00'),
            sender: 'dodavatel@example.invalid',
            subject: 'Doklad v příloze',
            text: 'V příloze posíláme doklad.',
            raw: '',
            attachments: [new EmailAttachment('doklad.pdf', 'application/pdf', $content)],
        );
        return $this->container->get(EmailPdfInvoiceIngestor::class)->ingestFromMessage(
            $this->mailbox,
            ['id' => null, 'ingest_pdf_invoices' => true],
            $message,
            $scope ?? $this->scope(),
        );
    }

    /** @return array<string,mixed>|null */
    private function submissionIn(int $supplierId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM purchase_invoice_submissions WHERE supplier_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function supplier(string $name, string $ic): int
    {
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Test database must have a synthetic supplier baseline.');
        $this->pdo->prepare("INSERT INTO supplier
                (company_name, ic, dic, street, city, zip, country_id, is_vat_payer, email,
                 default_currency_id, default_vat_rate_id, accounting_mode)
            SELECT ?, ?, ?, 'Synthetic street', 'Synthetic city', '00000', country_id, 0, 'synthetic@example.invalid',
                   default_currency_id, default_vat_rate_id, 'double_entry' FROM supplier WHERE id = ?")
            ->execute([$name . ' ' . bin2hex(random_bytes(3)), $ic, 'CZ' . $ic, $source]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<string> $keys */
    private function role(array $keys): int
    {
        $this->pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES ('Synthetic distribution role', 'staff', 1)")->execute();
        $id = (int) $this->pdo->lastInsertId();
        $insert = $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($keys as $key) {
            $insert->execute([$id, $key, AccessLevel::WRITE->value]);
        }
        return $id;
    }

    private function assign(int $supplierId, ?int $roleId): void
    {
        $this->pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$this->userId, $supplierId, $roleId]);
    }

    private function pdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = 'BT /F1 12 Tf 40 750 Td (' . $escaped . ') Tj ET';
        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
                . "/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF\n";
    }
}
