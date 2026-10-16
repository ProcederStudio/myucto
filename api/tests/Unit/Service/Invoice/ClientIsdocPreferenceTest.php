<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Mail\ClientIsdocPreference;
use MyInvoice\Service\Mail\InvoiceIsdocAttachments;
use MyInvoice\Service\Validation;
use MyInvoice\Tests\Support\StandaloneIsdocTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientIsdocPreferenceTest extends TestCase
{
    use StandaloneIsdocTestTrait;

    protected function setUp(): void
    {
        $this->setupIsdocDatabase();
        $this->pdo->exec('INSERT INTO clients VALUES (71, 41, 1, NULL), (72, 42, 1, NULL)');
    }

    public static function preferenceMatrix(): iterable
    {
        foreach ([false, true] as $supplier) {
            foreach ([null, false, true] as $client) {
                yield ($supplier ? 'supplier on' : 'supplier off') . ' / ' . var_export($client, true) => [$supplier, $client, $client ?? $supplier];
            }
        }
    }

    #[DataProvider('preferenceMatrix')]
    public function testClientOverridesSupplierOnlyForEmail(bool $supplier, ?bool $client, bool $expected): void
    {
        $this->pdo->prepare('UPDATE supplier SET email_attach_isdoc = ?, public_isdoc_enabled = ? WHERE id = 41')->execute([(int) $supplier, (int) $supplier]);
        $this->pdo->prepare('UPDATE clients SET email_attach_isdoc = ? WHERE id = 71')->execute([ClientIsdocPreference::toDatabase($client)]);
        $invoice = $this->isdocInvoice(['client_id' => 71, 'client_snapshot' => ['email_attach_isdoc' => !$expected]]);
        self::assertSame($expected, $this->delivery->emailEnabled($invoice));
        self::assertSame($supplier, $this->delivery->publicEnabled($invoice));
        $attachments = (new InvoiceIsdocAttachments($this->delivery, $this->isdocService(), new \Psr\Log\NullLogger()))
            ->forEmail('invoice_send', ['invoice' => $invoice], [['contentType' => 'application/pdf']]);
        self::assertCount($expected ? 2 : 1, $attachments);
        if ($expected) $this->assertIsdocSchema($attachments[1]['content']);
    }

    public function testForeignClientCannotOverrideEitherSupplierDefault(): void
    {
        foreach ([false, true] as $supplier) {
            $this->pdo->prepare('UPDATE supplier SET email_attach_isdoc = ? WHERE id = 41')->execute([(int) $supplier]);
            $this->pdo->prepare('UPDATE clients SET email_attach_isdoc = ? WHERE id = 72')->execute([(int) !$supplier]);
            foreach ([72, 999, 0] as $clientId) {
                self::assertSame($supplier, $this->delivery->emailEnabled($this->isdocInvoice(['client_id' => $clientId])));
            }
        }
        self::assertFalse($this->delivery->emailEnabled($this->isdocInvoice(['supplier_id' => 0, 'client_id' => 72])));
    }

    public function testExplicitOptInCannotExportDraftOrCancelled(): void
    {
        $this->pdo->exec('UPDATE clients SET email_attach_isdoc = 1 WHERE id = 71');
        foreach (['draft', 'cancelled'] as $status) {
            self::assertFalse($this->delivery->emailEnabled($this->isdocInvoice(['client_id' => 71, 'status' => $status])));
        }
    }

    public static function invalidValues(): iterable
    {
        foreach (['true', 'false', '', 'inherit', '0', '1', 0, 1, 2, 0.0, [], ['value' => true]] as $value) {
            yield [ $value ];
        }
    }

    #[DataProvider('invalidValues')]
    public function testInvalidPreferenceIsRejectedByValidationAndDatabaseConversion(mixed $value): void
    {
        self::assertArrayHasKey('email_attach_isdoc', Validation::client(['email_attach_isdoc' => $value]));
        $this->expectException(\InvalidArgumentException::class);
        ClientIsdocPreference::toDatabase($value);
    }

    public function testOmissionNullAndBooleansAreValid(): void
    {
        foreach ([[], ['email_attach_isdoc' => null], ['email_attach_isdoc' => false], ['email_attach_isdoc' => true]] as $data) {
            self::assertArrayNotHasKey('email_attach_isdoc', Validation::client($data));
        }
        self::assertNull(ClientIsdocPreference::toDatabase(null));
        self::assertSame(0, ClientIsdocPreference::toDatabase(false));
        self::assertSame(1, ClientIsdocPreference::toDatabase(true));
    }
}
