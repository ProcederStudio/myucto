<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MyInvoice;

use MyInvoice\Service\Migration\MyInvoice\MyInvoiceBackupScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MyInvoiceBackupScopeTest extends TestCase
{
    public function testExplicitStatementOwnerOverridesConflictingAccountMatch(): void
    {
        $all = $this->tables();
        $all['bank_statements'][1]['supplier_id'] = 1;
        $all['currencies'][2] = ['id' => 2, 'supplier_id' => 2, 'account_number' => '1000000005', 'bank_code' => '0100'];
        $selected = (new MyInvoiceBackupScope())->select($all, 1, 'double_entry', $this->history());
        self::assertCount(1, $selected['bank_statements']);
        self::assertSame('double_entry', $selected['supplier'][1]['accounting_mode']);
        self::assertArrayNotHasKey('email', $selected['supplier'][1]);
    }

    public function testWorkReportWithoutProjectBelongsToItsInvoice(): void
    {
        $all = $this->tables();
        $all['invoices'] = [11 => ['id' => 11, 'supplier_id' => 1], 12 => ['id' => 12, 'supplier_id' => 2]];
        $all['work_reports'] = [
            21 => ['id' => 21, 'invoice_id' => 11, 'project_id' => null],
            22 => ['id' => 22, 'invoice_id' => 12, 'project_id' => null],
        ];
        $selected = (new MyInvoiceBackupScope())->select($all, 1, 'tax_evidence', $this->history());
        self::assertSame([21], array_keys($selected['work_reports']));
        self::assertNull($selected['work_reports'][21]['project_id']);
    }

    public function testAmbiguousLegacyStatementIsRejected(): void
    {
        $all = $this->tables();
        $all['currencies'][2] = ['id' => 2, 'supplier_id' => 2, 'account_number' => '1000000005', 'bank_code' => '0100'];
        $this->expectExceptionMessage('jednoznačného vlastníka');
        (new MyInvoiceBackupScope())->select($all, 1, 'tax_evidence', $this->history());
    }

    #[DataProvider('invalidHistory')]
    public function testHistoryCannotBeInventedOrSilentlyDefaulted(array $history): void
    {
        $this->expectException(RuntimeException::class);
        (new MyInvoiceBackupScope())->select($this->tables(), 1, 'tax_evidence', $history);
    }

    public static function invalidHistory(): iterable
    {
        yield 'empty history' => [[]];
        yield 'invalid row' => [['invalid']];
        yield 'invalid date' => [[['effective_from' => '2090-02-30', 'is_vat_payer' => 1, 'is_identified' => 0, 'annual_deduction_percent' => null]]];
        yield 'missing flags' => [[['effective_from' => '1900-01-01']]];
        yield 'incompatible flags' => [[['effective_from' => '1900-01-01', 'is_vat_payer' => 1, 'is_identified' => 1, 'annual_deduction_percent' => null]]];
        yield 'missing deduction' => [[['effective_from' => '1900-01-01', 'is_vat_payer' => 1, 'is_identified' => 0]]];
        yield 'invalid deduction' => [[['effective_from' => '1900-01-01', 'is_vat_payer' => 1, 'is_identified' => 0, 'annual_deduction_percent' => -1]]];
    }

    private function tables(): array
    {
        return ['supplier' => [1 => ['id' => 1, 'company_name' => 'Synthetic', 'email' => 'excluded@example.test']],
            'currencies' => [1 => ['id' => 1, 'supplier_id' => 1, 'account_number' => '1000000005', 'bank_code' => '0100']],
            'bank_statements' => [1 => ['id' => 1, 'account_number' => '1000000005', 'bank_code' => '0100']]];
    }

    private function history(): array
    {
        return [['effective_from' => '1900-01-01', 'is_vat_payer' => 1, 'is_identified' => 0, 'annual_deduction_percent' => null]];
    }
}
