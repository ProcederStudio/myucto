<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxBankAccountSeries;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingPayments;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticNx1Archive.php';

final class StereoNxBankAccountSeriesTest extends TestCase
{
    private static function account(string $series = ''): array
    {
        return ['DoklRada' => $series, 'NazevUctu' => 'Syntetický účet',
            'BaUcet' => '1000000005', 'KodBanky' => '0100', 'IBAN' => ''];
    }

    public function testOnlyOneAccountAndOneDistinctStatementSeriesCanBeSuggested(): void
    {
        $plan = StereoNxBankAccountSeries::plan([self::account()],
            [['DoklRada' => ' B '], ['DoklRada' => 'B']]);
        self::assertSame(['requires_confirmation' => true, 'ambiguous' => false,
            'assignments' => [['account_index' => 0, 'account_label' => 'Syntetický účet', 'series' => 'B']],
            'missing_accounts' => 1, 'unmatched_series' => ['B']], $plan);
    }

    #[DataProvider('unsafeMappings')]
    public function testAmbiguousMappingsNeverSuggestAnAssignment(array $accounts, array $statements): void
    {
        $plan = StereoNxBankAccountSeries::plan($accounts, $statements);
        self::assertFalse($plan['requires_confirmation']);
        self::assertTrue($plan['ambiguous']);
        self::assertSame([], $plan['assignments']);
    }

    public static function unsafeMappings(): array
    {
        return [
            'two accounts' => [[self::account(), self::account()], [['DoklRada' => 'B']]],
            'two series' => [[self::account()], [['DoklRada' => 'B'], ['DoklRada' => 'C']]],
            'existing mapping cannot change' => [[self::account('C')], [['DoklRada' => 'B']]],
            'duplicate explicit mapping' => [[self::account('B'), self::account('B')], [['DoklRada' => 'B']]],
            'one missing among multiple accounts' => [[self::account('C'), self::account()], [['DoklRada' => 'B']]],
            'no account' => [[], [['DoklRada' => 'B']]],
            'statement has no series' => [[self::account()], [['DoklRada' => '']]],
            'blank account placeholder' => [[['DoklRada' => '', 'NazevUctu' => '']], [['DoklRada' => 'B']]],
        ];
    }

    public function testExplicitMappingAndUnusedBlankAccountNeedNoConfirmation(): void
    {
        $plan = StereoNxBankAccountSeries::plan([self::account('B'), self::account()], [['DoklRada' => 'B']]);
        self::assertFalse($plan['requires_confirmation']);
        self::assertFalse($plan['ambiguous']);
        self::assertSame([], $plan['assignments']);
        self::assertSame([], $plan['unmatched_series']);
    }

    public function testNoBankStatementsNeverRequiresConfirmation(): void
    {
        $plan = StereoNxBankAccountSeries::plan([self::account()], []);
        self::assertFalse($plan['requires_confirmation']);
        self::assertFalse($plan['ambiguous']);
        self::assertSame([], $plan['assignments']);
    }

    public function testNumericSeriesRemainStringsAndMissingLabelUsesSourceAccount(): void
    {
        $account = self::account();
        $account['NazevUctu'] = '';
        $plan = StereoNxBankAccountSeries::plan([$account], [['DoklRada' => '0']]);
        self::assertSame([['account_index' => 0, 'account_label' => '1000000005 / 0100', 'series' => '0']], $plan['assignments']);
    }

    public function testConfirmedCloneAppliesOnlySuggestedRowWithoutChangingSource(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stereo-series-');
        try {
            SyntheticNx1Archive::write($path,
                ['LFirmaUc' => [self::account()], 'CBanka' => [['DoklRada' => 'B']]],
                ['ico' => '00000000', 'dic' => '', 'name' => 'Syntetická firma', 'vat_payer' => false]);
            $hash = hash_file('sha256', $path);
            $backup = StereoNxBackup::open($path, 0);
            $inventory = $backup->inventory();
            $confirmed = $backup->withBankAccountSeriesMatching(true);
            self::assertNotSame($backup, $confirmed);
            self::assertSame('B', iterator_to_array($confirmed->rows('LFirmaUc'), false)[0]['DoklRada']);
            self::assertSame(iterator_to_array($backup->rows('CBanka'), false),
                iterator_to_array($confirmed->rows('CBanka'), false));
            self::assertSame('', iterator_to_array($backup->rows('LFirmaUc'), false)[0]['DoklRada']);
            self::assertSame('', iterator_to_array($backup->withBankAccountSeriesMatching(false)->rows('LFirmaUc'), false)[0]['DoklRada']);
            self::assertSame('', iterator_to_array($confirmed->withBankAccountSeriesMatching(false)->rows('LFirmaUc'), false)[0]['DoklRada']);
            self::assertSame($inventory, $confirmed->inventory());
            self::assertSame($hash, hash_file('sha256', $path));
            self::assertSame($backup->bankAccountSeriesPlan(), $confirmed->bankAccountSeriesPlan());
            self::assertSame([], $backup->bankAccountSeriesWarnings());
            self::assertSame([], $confirmed->withBankAccountSeriesMatching(false)->bankAccountSeriesWarnings());
            self::assertSame([['level' => 'warning', 'code' => 'bank_account_series_confirmed',
                'message' => 'Potvrzeno přiřazení bankovní řady „B“ ke zdrojovému účtu „Syntetický účet“.']],
                $confirmed->bankAccountSeriesWarnings());
            $confirmed->assertBankAccountSeriesReady();
            try {
                $backup->assertBankAccountSeriesReady();
                self::fail('Import without confirmation must fail.');
            } catch (StereoNxException $e) {
                self::assertSame('bank_account_series_confirmation_required', $e->errorCode);
                self::assertStringContainsString('potvrďte', $e->getMessage());
            }
        } finally {
            unlink($path);
        }
    }

    public function testConfirmationCannotResolveAmbiguousSource(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stereo-series-');
        try {
            SyntheticNx1Archive::write($path,
                ['LFirmaUc' => [self::account(), self::account()], 'CBanka' => [['DoklRada' => 'B']]],
                ['ico' => '00000000', 'dic' => '', 'name' => 'Syntetická firma', 'vat_payer' => false]);
            $backup = StereoNxBackup::open($path, 0);
            self::assertTrue($backup->bankAccountSeriesPlan()['ambiguous']);
            try {
                $backup->withBankAccountSeriesMatching(true);
                self::fail('Confirmation must not permit an ambiguous assignment.');
            } catch (StereoNxException $e) {
                self::assertSame('bank_account_series_ambiguous', $e->errorCode);
                self::assertStringContainsString('jednoznačně', $e->getMessage());
            }
        } finally {
            unlink($path);
        }
    }

    public function testIndependentPlanBuildersRequireMappingConfirmation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stereo-series-');
        try {
            SyntheticNx1Archive::write($path,
                ['LFirmaUc' => [self::account()], 'CBanka' => [['DoklRada' => 'B']]],
                ['ico' => '00000000', 'dic' => '', 'name' => 'Syntetická firma', 'vat_payer' => false]);
            $backup = StereoNxBackup::open($path, 0);
            $payments = (new \ReflectionClass(StereoNxAccountingPayments::class))->newInstanceWithoutConstructor();
            foreach ([fn () => StereoNxSourcePlan::build($backup), fn () => $payments->prepare($backup)] as $build) {
                try {
                    $build();
                    self::fail('Independent preparation must require confirmation.');
                } catch (StereoNxException $e) {
                    self::assertSame('bank_account_series_confirmation_required', $e->errorCode);
                }
            }
        } finally {
            unlink($path);
        }
    }
}
