<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioSelectorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JmhzScenarioSelectorResolverTest extends TestCase
{
    public function testSelectorsShareOneValidatedCatalog(): void
    {
        $resolver = JmhzScenarioSelectorResolver::load();
        $scenarios = new \ReflectionProperty($resolver, 'scenarios');
        $scenarioOne = (new \ReflectionProperty($resolver, 'scenarioOne'))->getValue($resolver);
        $nestedScenarios = new \ReflectionProperty($scenarioOne, 'scenarios');

        self::assertSame($scenarios->getValue($resolver), $nestedScenarios->getValue($scenarioOne));
        // Katalog z přibalených zdrojů se ověří jednou za proces a další načtení ho sdílí.
        self::assertSame(
            $scenarios->getValue($resolver),
            $scenarios->getValue(JmhzScenarioSelectorResolver::load()),
        );
        self::assertTrue($resolver->resolve('A', null)['preparation_supported']);
        self::assertSame('scenario_2', $resolver->resolve('M', '1')['evidence']['scenario_key']);
    }

    /** @return iterable<string,array{string,?string,?string,string,string}> */
    public static function pinnedSelectors(): iterable
    {
        yield 'foster-carer' => ['M', '1', null, 'scenario_2', 'formPestoun.xsd'];
        yield 'specific-relationship' => ['1', '3', null, 'scenario_3', 'formCinnostKS.xsd'];
        yield 'disability-training' => ['10', null, null, 'scenario_7', 'formOzpTpp.xsd'];
        yield 'explicit-deferred-income' => ['A', null, 'scenario_8', 'scenario_8', 'formOdlozenyPrijem.xsd'];
    }

    #[DataProvider('pinnedSelectors')]
    public function testClassifiesPinnedScenariosAndCarriesImmutableSourceEvidence(
        string $activityCode,
        ?string $detailCode,
        ?string $manualScenarioKey,
        string $scenarioKey,
        string $entrypoint,
    ): void {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve(
            $activityCode,
            $detailCode,
            $manualScenarioKey,
        );

        self::assertTrue($resolution['supported']);
        self::assertSame($scenarioKey, $resolution['evidence']['scenario_key'] ?? null);
        self::assertSame($entrypoint, $resolution['evidence']['xsd_entrypoint'] ?? null);
        self::assertFalse($resolution['preparation_supported']);
        self::assertSame(
            $scenarioKey === 'scenario_8'
                ? 'deferred_income_evidence_missing'
                : "jmhz_{$scenarioKey}_preparation_unsupported",
            $resolution['readiness_issue_code'],
        );
        if ($scenarioKey !== 'scenario_8') {
            // Formulář aplikace nevydává: nález musí účetní poslat podat
            // hlášení ručně, ne skončit jako „neznámá blokace".
            self::assertTrue(JmhzBlockerCatalog::knows((string) $resolution['readiness_issue_code']));
            self::assertSame(
                'manual',
                JmhzBlockerCatalog::remediation((string) $resolution['readiness_issue_code'])['kind'],
            );
        }
        self::assertNotEmpty($resolution['readiness_attribute_ids']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) ($resolution['evidence']['scenario_row_sha256'] ?? null),
        );
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) ($resolution['evidence']['matrix_sha256'] ?? null),
        );
    }

    /**
     * Datové scénáře 4 až 6 (vězeň, jiný příjem, mezinárodní pronájem síly)
     * aplikace sestaví: zařazení nese důkaz katalogu a žádný nález
     * připravenosti.
     *
     * @return iterable<string,array{string,string,string,string}>
     */
    public static function preparedSpecialSelectors(): iterable
    {
        yield 'prison-service' => ['1', '2', 'scenario_4', 'formVezen.xsd'];
        yield 'prison-service-9' => ['9', '2', 'scenario_4', 'formVezen.xsd'];
        yield 'other-income-11' => ['11', '1', 'scenario_5', 'formJinyPrijem.xsd'];
        yield 'other-income-13' => ['13', '1', 'scenario_5', 'formJinyPrijem.xsd'];
        yield 'other-income-14' => ['14', '1', 'scenario_5', 'formJinyPrijem.xsd'];
        yield 'international-hire' => ['12', '1', 'scenario_6', 'formMezinarodniPronajemSily.xsd'];
    }

    #[DataProvider('preparedSpecialSelectors')]
    public function testPreparesPrisonerOtherIncomeAndInternationalHireScenarios(
        string $activityCode,
        string $detailCode,
        string $scenarioKey,
        string $entrypoint,
    ): void {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve($activityCode, $detailCode);

        self::assertTrue($resolution['supported']);
        self::assertSame($scenarioKey, $resolution['evidence']['scenario_key'] ?? null);
        self::assertSame($entrypoint, $resolution['evidence']['xsd_entrypoint'] ?? null);
        self::assertTrue($resolution['preparation_supported']);
        self::assertNull($resolution['readiness_issue_code']);
        self::assertSame([], $resolution['readiness_attribute_ids']);
    }

    public function testDoesNotInferManualDeferredIncomeScenario(): void
    {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve('A', null);

        self::assertSame('scenario_1', $resolution['evidence']['scenario_key'] ?? null);
    }

    /**
     * Kontrola 343 řadí do `cinnostKS` druhy K a N až S. Prokurista (P),
     * člen kolektivního orgánu (Q) nebo likvidátor (R) se vykazují stejným
     * formulářem jako jednatel (S); přijatá hlášení jiného systému nesou P.
     * Specifická skupina u pracovního poměru (1–9 s bližším určením 3) ale
     * profil statutára není a zůstává nepodporovaná.
     *
     * @return iterable<string,array{string}>
     */
    public static function corporateBodyActivities(): iterable
    {
        foreach (['K', 'N', 'O', 'P', 'Q', 'R', 'S'] as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('corporateBodyActivities')]
    public function testEnablesCorporateBodyActivitiesForScenarioThreePreparation(string $activityCode): void
    {
        $supported = JmhzScenarioSelectorResolver::load()->resolve($activityCode, '1');

        self::assertSame('scenario_3', $supported['evidence']['scenario_key'] ?? null);
        self::assertSame('formCinnostKS.xsd', $supported['evidence']['xsd_entrypoint'] ?? null);
        self::assertTrue($supported['preparation_supported']);
        self::assertNull($supported['readiness_issue_code']);
        self::assertSame([], $supported['readiness_attribute_ids']);
    }

    public function testSpecificGroupEmploymentStaysOutsideScenarioThreePreparation(): void
    {
        $otherScenarioThree = JmhzScenarioSelectorResolver::load()->resolve('1', '3');

        self::assertSame('scenario_3', $otherScenarioThree['evidence']['scenario_key'] ?? null);
        self::assertFalse($otherScenarioThree['preparation_supported']);
        self::assertSame(
            'jmhz_scenario_3_preparation_unsupported',
            $otherScenarioThree['readiness_issue_code'],
        );
    }

    public function testDeferredIncomeIsForbiddenForActivityKindTen(): void
    {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve('10', null, 'scenario_8');

        self::assertFalse($resolution['supported']);
        self::assertSame('jmhz_scenario_8_activity_10_forbidden', $resolution['issue_code']);
    }

    public function testRejectsUnknownManualScenario(): void
    {
        $resolution = JmhzScenarioSelectorResolver::load()->resolve('A', '1', 'scenario_7');

        self::assertFalse($resolution['supported']);
        self::assertSame('jmhz_scenario_manual_selection_invalid', $resolution['issue_code']);
    }
}
