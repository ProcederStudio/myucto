<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property\Payments;
use MyInvoice\Service\Property\Payments\ClaimPortionAllocator;
use PHPUnit\Framework\TestCase;
final class ClaimPortionAllocatorTest extends TestCase
{
    public function testAccountingRowOrderCannotChoosePartialLegalDisposition():void
    {
        $this->expectException(\DomainException::class);ClaimPortionAllocator::distribute('60',[19=>'70',2=>'30'],null);
    }
    public function testExplicitSameDebtBreakdownPreservesExactAmounts():void
    {
        self::assertSame([2=>'10',19=>'50'],ClaimPortionAllocator::distribute('60',[19=>'70',2=>'30'],['evidence'=>'Synthetic legal allocation within same debt','parts'=>[['id'=>19,'amount_cents'=>'50'],['id'=>2,'amount_cents'=>'10']]]));
        self::assertSame([2=>'30',19=>'70'],ClaimPortionAllocator::distribute('100',[19=>'70',2=>'30'],null));
    }
    public function testDocumentedBreakdownCannotExceedActualReceipt():void
    {
        $this->expectException(\DomainException::class);ClaimPortionAllocator::distribute('60',[19=>'40',2=>'60'],['evidence'=>'Synthetic excess','parts'=>[['id'=>19,'amount_cents'=>'50'],['id'=>2,'amount_cents'=>'10']]]);
    }
}
