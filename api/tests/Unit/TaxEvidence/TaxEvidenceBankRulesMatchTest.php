<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\TaxEvidence;

use MyInvoice\Service\TaxEvidence\TaxEvidenceBankRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxEvidenceBankRulesMatchTest extends TestCase
{
    #[DataProvider('bankAccounts')]
    public function testBankOnlyRule(?string $account, ?string $bank, bool $expected): void
    {
        $rule = TaxEvidenceBankRules::validate([
            'name' => 'Test banky', 'counterparty_account' => '*/5050', 'action_ignore' => true,
        ])['data'];
        self::assertSame($expected, TaxEvidenceBankRules::matches($rule, [
            'amount' => 100, 'counterparty_account' => $account, 'counterparty_bank' => $bank,
        ]));
    }

    public static function bankAccounts(): iterable
    {
        yield 'separate bank' => ['1000000005', '5050', true];
        yield 'different bank' => ['1000000005', '0100', false];
        yield 'account suffix' => ['1000000005/5050', null, true];
        yield 'different suffix' => ['1000000005/0100', null, false];
        yield 'spaces' => [' 1000000005 / 5050 ', null, true];
        yield 'CZ IBAN' => ['CZ5350500000001000000005', null, true];
        yield 'unknown bank' => ['1000000005', null, false];
        yield 'digits are not a bank code' => ['5050', null, false];
        yield 'no account' => [null, '5050', true];
        yield 'no data' => [null, null, false];
    }

    #[DataProvider('exactAccounts')]
    public function testAccountAndBankMatching(string $pattern, ?string $account, ?string $bank, bool $expected): void
    {
        $rule = TaxEvidenceBankRules::validate([
            'name' => 'Test účtu', 'counterparty_account' => $pattern, 'action_ignore' => true,
        ])['data'];
        self::assertSame($expected, TaxEvidenceBankRules::matches($rule, [
            'amount' => 1, 'counterparty_account' => $account, 'counterparty_bank' => $bank,
        ]));
    }

    public static function exactAccounts(): iterable
    {
        yield 'explicit account and bank' => ['1000000005/5050', '1000000005', '5050', true];
        yield 'explicit account wrong bank' => ['1000000005/5050', '1000000005', '0100', false];
        yield 'explicit account missing bank' => ['1000000005/5050', '1000000005', null, false];
        yield 'explicit account suffix' => ['1000000005/5050', '1000000005/5050', null, true];
        yield 'account without bank matches suffix' => ['1000000005', '1000000005/0100', null, true];
        yield 'account without bank matches IBAN' => ['1000000005', 'CZ5350500000001000000005', null, true];
        yield 'explicit account matches IBAN' => ['1000000005/5050', 'CZ5350500000001000000005', null, true];
        yield 'IBAN rule preserves bank' => ['CZ5350500000001000000005', '1000000005', '0100', false];
        yield 'IBAN rule matches domestic' => ['CZ5350500000001000000005', '1000000005', '5050', true];
        yield 'prefix and GPC padding' => ['19-1000000005/5050', '0000191000000005', '5050', true];
        yield 'different prefix' => ['19-1000000005/5050', '0000201000000005', '5050', false];
        yield 'foreign exact account' => ['DE00 1234 0000 0000 0000 00', 'DE00123400000000000000', null, true];
        yield 'malformed stored wildcard fails closed' => ['100*/5050', '1005050', null, false];
    }

    #[DataProvider('invalidPatterns')]
    public function testRejectsInvalidAccountPatterns(string $pattern): void
    {
        $result = TaxEvidenceBankRules::validate([
            'name' => 'Test', 'counterparty_account' => $pattern, 'action_ignore' => true,
        ]);
        self::assertArrayHasKey('counterparty_account', $result['errors']);
    }

    public static function invalidPatterns(): iterable
    {
        foreach (['*', '*/505', '*/50500', '100*/5050', '**/5050', '1000000005/*',
            '1000000005/505', '1000000005/5050/0100', '*/abcd', '*/5050' . str_repeat(' ', 58) . '*',
            '1000000005' . str_repeat(' ', 64) . '/5050'] as $pattern) {
            yield $pattern => [$pattern];
        }
    }

    public function testBankRuleStillRequiresOtherCriteria(): void
    {
        $rule = TaxEvidenceBankRules::validate([
            'name' => 'Test', 'counterparty_account' => '*/5050', 'direction' => 'outgoing',
            'variable_symbol' => '123', 'text_contains' => 'poplatek', 'amount_max' => 200,
            'action_ignore' => true,
        ])['data'];
        $tx = ['amount' => -100, 'counterparty_account' => '1000000005', 'counterparty_bank' => '5050',
            'variable_symbol' => '123', 'description' => 'Poplatek'];
        self::assertTrue(TaxEvidenceBankRules::matches($rule, $tx));
        foreach (['amount' => 100, 'variable_symbol' => '124', 'description' => 'Převod'] as $field => $value) {
            self::assertFalse(TaxEvidenceBankRules::matches($rule, array_replace($tx, [$field => $value])));
        }
    }

    public function testExactAccountMatchingIsPreserved(): void
    {
        $rule = TaxEvidenceBankRules::validate([
            'name' => 'Test', 'counterparty_account' => '1000000005', 'action_ignore' => true,
        ])['data'];
        self::assertTrue(TaxEvidenceBankRules::matches($rule, ['amount' => 1, 'counterparty_account' => '00001000000005']));
        self::assertFalse(TaxEvidenceBankRules::matches($rule, ['amount' => 1, 'counterparty_account' => '19-1000000005']));
    }
}
