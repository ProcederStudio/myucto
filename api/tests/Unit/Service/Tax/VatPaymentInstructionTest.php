<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tax;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Tax\TaxOfficeBankAccounts;
use MyInvoice\Service\Tax\VatPaymentInstruction;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class VatPaymentInstructionTest extends TestCase
{
    private static function xml(string $forma, string $veta6, string $ufo = '454'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Pisemnost nazevSW="test"><DPHDP3 verzePis="01.02">'
            . '<VetaD dokument="DP3" k_uladis="DPH" dapdph_forma="' . $forma . '" rok="2026" ctvrt="3"/>'
            . '<VetaP c_ufo="' . $ufo . '" dic="24681357" typ_ds="P"/>'
            . '<Veta6 ' . $veta6 . '/>'
            . '</DPHDP3></Pisemnost>';
    }

    /** @return array<string,mixed> */
    private static function submission(string $xml, ?int $month = null, ?int $quarter = 3): array
    {
        return [
            'form_code' => 'dphdp3',
            'period_year' => 2026,
            'period_month' => $month,
            'period_quarter' => $quarter,
            'xml_content' => $xml,
        ];
    }

    private function service(): VatPaymentInstruction
    {
        return new VatPaymentInstruction(new QrPaymentGenerator(new Config([]), new NullLogger()));
    }

    public function testOfficeAccountFollowsOfficialList(): void
    {
        $plzen = TaxOfficeBankAccounts::account('454', TaxOfficeBankAccounts::PREFIX_VAT);
        self::assertNotNull($plzen);
        self::assertSame('705-77627311/0710', $plzen['account']);
        self::assertSame('CZ1707100007050077627311', $plzen['iban']);
        self::assertSame('Finanční úřad pro Plzeňský kraj', $plzen['office_name']);

        self::assertSame('705-77620021/0710', TaxOfficeBankAccounts::account('13', '705')['account'] ?? null);
        self::assertSame('705-67626681/0710', TaxOfficeBankAccounts::account('460', '705')['account'] ?? null);
        self::assertNull(TaxOfficeBankAccounts::account('999', '705'));
        self::assertNull(TaxOfficeBankAccounts::account(null, '705'));
    }

    public function testRegularReturnPaysOwnTaxToOfficeFromXml(): void
    {
        $out = $this->service()->forSubmission(self::submission(
            self::xml('B', 'dan_zocelk="443837" odp_zocelk="139315" dano_da="304522"'),
        ));

        self::assertTrue($out['supported']);
        self::assertSame(304522.0, $out['payable']);
        self::assertSame(0.0, $out['refund']);
        self::assertSame('24681357', $out['variable_symbol']);
        self::assertSame('705-77627311/0710', $out['account']['account']);
        // 25. 10. 2026 je neděle → pondělí 26. 10.
        self::assertSame('2026-10-26', $out['due_date']);
        self::assertIsString($out['qr_data_uri']);
    }

    public function testExcessDeductionHasNothingToPay(): void
    {
        $out = $this->service()->forSubmission(self::submission(
            self::xml('B', 'dan_zocelk="1000" odp_zocelk="5000" dano_no="4000"'),
        ));

        self::assertSame(0.0, $out['payable']);
        self::assertSame(4000.0, $out['refund']);
        self::assertNull($out['qr_data_uri']);
    }

    public function testAmendmentPaysDifferenceWithoutComputedDueDate(): void
    {
        $out = $this->service()->forSubmission(self::submission(
            self::xml('D', 'dan_zocelk="2100" odp_zocelk="0" dano="2100"'),
        ));

        self::assertTrue($out['amendment']);
        self::assertSame(2100.0, $out['payable']);
        self::assertNull($out['due_date']);
    }

    public function testDueDateRollsOverYearForDecemberAndLastQuarter(): void
    {
        self::assertSame('2027-01-25', VatPaymentInstruction::dueDate(self::submission('', 12, null)));
        self::assertSame('2027-01-25', VatPaymentInstruction::dueDate(self::submission('', null, 4)));
        self::assertSame('2026-06-25', VatPaymentInstruction::dueDate(self::submission('', 5, null)));
    }

    public function testOtherFormsAreNotSupported(): void
    {
        self::assertSame(['supported' => false], $this->service()->forSubmission(['form_code' => 'dphkh1']));
    }

    public function testQrPayloadTargetsOfficeAccount(): void
    {
        $account = TaxOfficeBankAccounts::account('454', TaxOfficeBankAccounts::PREFIX_VAT);
        self::assertNotNull($account);
        $payload = (new QrPaymentGenerator(new Config([]), new NullLogger()))->buildPayload(
            'CZK',
            304522.0,
            '24681357',
            ['account_number' => '705-77627311', 'bank_code' => '0710', 'iban' => '', 'bic' => ''],
        );
        self::assertNotNull($payload);
        self::assertStringContainsString('ACC:' . $account['iban'], $payload);
        self::assertStringContainsString('X-VS:24681357', $payload);
    }
}
