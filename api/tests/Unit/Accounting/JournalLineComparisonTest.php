<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\JournalLineComparison;
use PHPUnit\Framework\TestCase;

final class JournalLineComparisonTest extends TestCase
{
    private function line(): array
    {
        return ['account_id'=>1,'side'=>'debit','amount'=>'49.99','dimensions'=>[4=>12,2=>8],
            'dimension_splits'=>[5=>[13=>'0.40',14=>'0.60']]];
    }

    public function testOrderAndDecimalFormattingPreserveExactIdentity(): void
    {
        $a=$this->line();$b=$a;$b['amount']=49.99;$b['dimensions']=[2=>'8',4=>'12'];
        $b['dimension_splits']=[5=>[14=>0.6,13=>0.4]];
        self::assertTrue(JournalLineComparison::sameLines([$a,['account_id'=>2,'side'=>'credit','amount'=>49.99]],
            [['account_id'=>2,'side'=>'credit','amount'=>'49.990'],$b]));
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['account_id',2])]
    #[\PHPUnit\Framework\Attributes\TestWith(['side','credit'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['amount','49.98'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['is_red_storno',true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['currency_code','EUR'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['fx_rate',25.01])]
    #[\PHPUnit\Framework\Attributes\TestWith(['amount_foreign',2])]
    #[\PHPUnit\Framework\Attributes\TestWith(['project_id',7])]
    #[\PHPUnit\Framework\Attributes\TestWith(['cost_center','SYNTHETIC'])]
    public function testEveryFinancialFieldIsCompared(string $field,mixed $value): void
    {
        $a=$this->line();$b=$a;$b[$field]=$value;
        self::assertFalse(JournalLineComparison::sameLines([$a],[$b]));
    }

    public function testAssignmentsAreAdditionalToLegacyFinancialComparison(): void
    {
        $a=$this->line();$b=$a;$b['dimensions'][4]=15;
        self::assertTrue(JournalLineComparison::sameLedgerLine($a,$b));
        self::assertFalse(JournalLineComparison::sameLines([$a],[$b]));
        $b=$a;$b['dimension_splits'][5]=[13=>0.41,14=>0.59];
        self::assertFalse(JournalLineComparison::sameLines([$a],[$b]));
        $b=$a;unset($b['dimensions']);
        self::assertFalse(JournalLineComparison::sameLines([$a],[$b]));
    }

    public function testDuplicateLinesCannotReuseOneMatchingCounterpart(): void
    {
        $a=$this->line();$b=$a;$b['account_id']=2;
        self::assertFalse(JournalLineComparison::sameLines([$a,$a],[$a,$b]));
        self::assertFalse(JournalLineComparison::sameLines([$a,$a],[$a]));
    }
}
