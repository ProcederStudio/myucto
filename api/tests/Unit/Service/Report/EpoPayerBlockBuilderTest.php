<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoPayerBlockBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EpoPayerBlockBuilderTest extends TestCase
{
    #[DataProvider('payerSchemas')]
    public function testPersonTitleIsExportedAndPassesPayerSchema(string $schema, array $header): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->appendChild($dom->createElement('Pisemnost'));
        $form = $root->appendChild($dom->createElement(strtoupper($schema)));
        $d = $form->appendChild($dom->createElement('VetaD'));
        foreach ($header as $key => $value) {
            $d->setAttribute($key, $value);
        }
        $p = $form->appendChild($dom->createElement('VetaP'));
        EpoPayerBlockBuilder::fillVetaP($p, $this->supplier(['person_title' => ' Ing. Ph.D. ']));
        self::assertSame('Ing. Ph.D.', $p->getAttribute('titul'));
        self::assertSame('Jan', $p->getAttribute('jmeno'));
        self::assertSame('Novák', $p->getAttribute('prijmeni'));
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $valid = $dom->schemaValidate(dirname(__DIR__, 4) . '/xsd/' . $schema . '.xsd');
            $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
            self::assertTrue($valid, implode("\n", $errors));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function payerSchemas(): iterable
    {
        yield 'DPZVD6' => ['dpzvd6', ['k_uladis' => 'DPZ', 'dokument' => 'VD6', 'c_ufo_cil' => '451', 'vdadpz_typ' => 'R', 'zdobd_od' => '1.1.2025', 'zdobd_do' => '31.12.2025']];
        yield 'DPSVD2' => ['dpsvd2', ['k_uladis' => 'DPS', 'dokument' => 'VD2', 'c_ufo_cil' => '451', 'c_drp' => '1', 'dapdps_forma' => 'R', 'zdobd_od' => '1.1.2025', 'zdobd_do' => '31.12.2025']];
        $bonus = ['k_uladis' => 'DPZ', 'c_ufo_cil' => '451', 'kc_bonus_celk' => '1000', 'kc_zalohy' => '200', 'kc_bonus_vl' => '800', 'd_bonus' => '15.2.2025'];
        yield 'DPZMB1' => ['dpzmb1', ['dokument' => 'MB1', 'bonus_mesic' => '1', 'bonus_rok' => '2025'] + $bonus];
        yield 'DPZDB1' => ['dpzdb1', ['dokument' => 'DB1', 'bonus_zdobd' => '2024'] + $bonus];
    }

    public function testTitleIsOmittedForLegalPersonAndEmptyValue(): void
    {
        foreach ([['taxpayer_type' => 'po', 'person_title' => 'Ing.'], ['person_title' => null], ['person_title' => ' ']] as $fields) {
            $p = (new \DOMDocument())->createElement('VetaP');
            EpoPayerBlockBuilder::fillVetaP($p, $this->supplier($fields));
            self::assertFalse($p->hasAttribute('titul'));
        }
    }

    public function testForeignIncomeFormsCanSuppressUnsupportedTitle(): void
    {
        $p = (new \DOMDocument())->createElement('VetaP');
        EpoPayerBlockBuilder::fillVetaP($p, $this->supplier(['person_title' => 'Ing.']), includePersonTitle: false);
        self::assertFalse($p->hasAttribute('titul'));
        self::assertSame('Jan', $p->getAttribute('jmeno'));
    }

    private function supplier(array $overrides): array
    {
        return $overrides + [
            'taxpayer_type' => 'fo', 'company_name' => 'Jan Novák', 'dic' => 'CZ1234567890',
            'opr_jmeno' => 'Jan', 'opr_prijmeni' => 'Novák',
            'street' => 'Zkušební 1', 'city' => 'Vzorov', 'zip' => '11111',
        ];
    }
}
