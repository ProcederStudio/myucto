<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Service\Anonymization\Nonprofit\J6CloneLegacyInputTransformer;
use MyInvoice\Service\Anonymization\Nonprofit\J6CloneTypedTransformer;
use MyInvoice\Service\Anonymization\Nonprofit\J6RebasedDecisionProvider;
use MyInvoice\Service\Anonymization\Nonprofit\J6RebasedFundingEvidenceProvider;
use MyInvoice\Service\Anonymization\Nonprofit\J6RebasedSourceProjection;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AuthoritativeSourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DecisionContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;
use PHPUnit\Framework\TestCase;

final class J6CloneTypedTransformerTest extends TestCase
{
    public function testTypedProjectionScrubsOnlyPresentationFieldsAndPreservesFinancialAndExternalEvidence(): void
    {
        $external = ['version' => 3, 'payload' => ['email' => 'external-owner@example.test'],
            'hash' => hash('sha256', 'external-owner@example.test')];
        $source = $this->sourceProvider([$this->sourceRow($external)]);
        $projection = new J6RebasedSourceProjection($source, $this->transformer());

        $header = $projection->header();
        $pages = iterator_to_array($projection->pages(), false);
        $row = $pages[0]->rows[0];

        self::assertSame(50010, $row['amount_cents']);
        self::assertSame('2026-10-19', $row['date']);
        self::assertSame(77, $row['origin']['line_id']);
        self::assertSame($external, $row['provenance']['external_receipt']);
        self::assertNotSame('private-axis@example.test', $row['axes']['activity']['name']);
        self::assertStringNotContainsString('@example.test', $row['axes']['activity']['name']);
        self::assertSame('private-profile@example.test', $source->header()->data['legal_profile']['external_receipt']['payload']['email']);
        self::assertNotSame('private-profile@example.test', $header->data['legal_profile']['evidence']);
        self::assertSame(0, $projection->findingCount());

        $this->expectException(\LogicException::class);
        iterator_to_array($projection->pages(), false);
    }

    public function testDecisionAndFundingProvidersRehashScrubbedRowsAndRejectForeignContexts(): void
    {
        $oldHash = hash('sha256', 'old-source');
        $newHash = hash('sha256', 'new-source');
        $historic = new J6DecisionContext(11, 13, J6ReadMode::HISTORIC, $oldHash,
            assessmentId: 17, decisionManifestId: 19);
        $rebased = new J6DecisionContext(11, 13, J6ReadMode::REBASED_HISTORIC, $newHash,
            assessmentId: 17, decisionManifestId: 23);
        $decisions = new class ($historic) implements J6AssessmentDecisionProvider {
            public function __construct(private J6DecisionContext $context) {}
            public function context(): J6DecisionContext { return $this->context; }
            public function header(): array { return ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'private-header@example.test'],
                'source_input_hash' => $this->context->sourceInputHash]; }
            public function decisionPages(int $limit = 500): iterable { yield [['source_id' => 'line:77',
                'payload' => ['title' => 'rent', 'evidence' => 'private-decision@example.test', 'amount_cents' => 50010],
                'record_hash' => hash('sha256', 'old')]]; }
            public function activityPages(int $limit = 500): iterable { yield []; }
            public function fundingPoolPages(int $limit = 500): iterable { yield []; }
            public function fundingMemberPages(int $limit = 500): iterable { yield []; }
            public function fundingLinkPages(int $limit = 500): iterable { yield []; }
        };
        $provider = new J6RebasedDecisionProvider($decisions, $this->transformer(), $rebased);
        $header = $provider->header();
        $record = iterator_to_array($provider->decisionPages(), false)[0][0];

        self::assertNotSame('private-header@example.test', $header['profile']['evidence']);
        self::assertSame($newHash, $header['source_input_hash']);
        self::assertSame(50010, $record['payload']['amount_cents']);
        self::assertNotSame('private-decision@example.test', $record['payload']['evidence']);
        self::assertSame(NonprofitTaxRules::hash(['domain' => 'J6DRAFT.source', 'keys' => ['line:77'],
            'payload' => $record['payload']]), $record['record_hash']);

        $source = $this->sourceProvider([$this->sourceRow([])], $historic);
        $newContext = new J6ReadContext(11, 13, J6ReadMode::REBASED_HISTORIC, 17, 31);
        $funding = new J6RebasedFundingEvidenceProvider($source, $this->transformer(), $newContext);
        $reference = iterator_to_array($funding->fundingReferencePages($newContext), false)[0][0];
        self::assertSame(50010, $reference['amount_cents']);
        self::assertNotSame('private-funding@example.test', $reference['evidence']);

        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($funding->fundingReferencePages(
            new J6ReadContext(11, 13, J6ReadMode::REBASED_HISTORIC, 18, 31)), false);
    }

    public function testLegacyPageTransformerIsBoundedAndAuthorizesOnlyFreshDerivedHash(): void
    {
        $legacy = new J6CloneLegacyInputTransformer($this->transformer());
        $source = ['id' => 'line:77', 'line_id' => 77, 'kind' => 'revenue', 'amount_cents' => 50010,
            'activity' => 'main', 'activity_kind_id' => 2, 'tax_regime' => 'subject',
            'mandatory_non_deductible' => false, 'module_facts' => [],
            'axes' => ['activity' => ['type_id' => 1, 'value_id' => 2, 'name' => 'private-axis@example.test']]];
        $sourcePage = $legacy->transformPage('source', [$source]);
        self::assertSame(50010, $sourcePage[0]['amount_cents']);
        self::assertStringNotContainsString('@example.test', $sourcePage[0]['axes']['activity']['name']);

        $result = ['id' => 'line:77', 'amount_cents' => 50010, 'evidence' => 'private-result@example.test',
            'result_row_hash' => hash('sha256', 'old-result')];
        $rebased = $legacy->transformPage('result', [$result]);
        self::assertSame(50010, $rebased[0]['amount_cents']);
        self::assertNotSame($result['result_row_hash'], $rebased[0]['result_row_hash']);
        self::assertTrue($legacy->permitsIdentityChange('result', ['result_row_hash'],
            $result['result_row_hash'], $rebased[0]['result_row_hash']));
        self::assertFalse($legacy->permitsIdentityChange('result', ['result_row_hash'],
            $result['result_row_hash'], $rebased[0]['result_row_hash']));
        self::assertFalse($legacy->permitsIdentityChange('result', ['amount_cents'], 50010, 50011));

        $this->expectException(\InvalidArgumentException::class);
        $legacy->transformPage('result', array_fill(0, 501, $result));
    }

    public function testUnknownTypedStringFieldFailsClosed(): void
    {
        $transformer = $this->transformer();
        $record = ['source_id' => 'line:77', 'payload' => ['title' => 'rent', 'untyped_text' => 'private@example.test'],
            'record_hash' => hash('sha256', 'old')];

        $this->expectException(\UnexpectedValueException::class);
        $transformer->decisionRecord('source', $record);
    }

    private function transformer(): J6CloneTypedTransformer
    {
        return new J6CloneTypedTransformer(new TextScrubber(new Pseudonymizer(hash('sha256', 'j6-clone-test', true))));
    }

    private function sourceProvider(array $rows, ?J6DecisionContext $decisionContext = null): J6AuthoritativeSourceProvider
    {
        $context = new J6ReadContext(11, 13, J6ReadMode::HISTORIC, 17, 19);
        return new class ($rows, $context) implements J6AuthoritativeSourceProvider {
            public function __construct(private array $rows, private J6ReadContext $context) {}
            public function context(): J6ReadContext { return $this->context; }
            public function header(): SnapshotHeaderV2
            {
                return new SnapshotHeaderV2(['schema' => SnapshotHeaderV2::SCHEMA, 'scope' => 'current-period-source',
                    'supplier_id' => 11, 'period_id' => 13,
                    'period' => ['id' => 13, 'fiscal_year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'],
                    'frameworks' => ['cz_504'], 'ruleset' => NonprofitTaxRules::VERSION,
                    'funding_history_mode' => 'paged_assessment_graph_v1', 'source_fact_schemas' => [],
                    'legal_profile' => ['evidence' => 'private-profile@example.test',
                        'external_receipt' => ['payload' => ['email' => 'private-profile@example.test']]]]);
            }
            public function pages(?SnapshotCursor $after = null, int $limit = 500): iterable
            {
                yield new SnapshotPageV2($this->rows, [], null, null, null, count($this->rows));
            }
            public function fundingReferencePages(int $limit = 500): iterable
            {
                yield [['source_id' => 'line:77', 'amount_cents' => 50010,
                    'evidence' => 'private-funding@example.test', 'result_row_hash' => hash('sha256', 'old-result')]];
            }
            public function fundingHistoryPages(int $limit = 500): iterable
            {
                yield [['assessment_id' => 17, 'amount_cents' => 50010, 'status' => 'confirmed',
                    'record_hash' => hash('sha256', 'old-history')]];
            }
        };
    }

    private function sourceRow(array $external): array
    {
        $axis = static fn (int $type, int $value, string $semantic, string $name): array => [
            'type_id' => $type, 'value_id' => $value, 'semantic_key' => $semantic, 'code' => $name,
            'name' => $name, 'assignment_origin' => 'direct', 'assignment_origin_ref' => 'line:77'];
        return ['id' => 'line:77', 'line_id' => 77, 'entry_id' => 70, 'line_no' => 1, 'account_id' => 60,
            'account_code' => '602100', 'date' => '2026-10-19', 'kind' => 'revenue', 'amount_cents' => 50010,
            'activity' => 'main', 'activity_kind_id' => 2, 'tax_regime' => 'subject',
            'mandatory_non_deductible' => false, 'neutral' => false,
            'axes' => ['activity' => $axis(1, 1, 'main', 'private-axis@example.test'),
                'activity_kind' => $axis(2, 2, 'membership', 'private-kind@example.test'),
                'tax_regime' => $axis(3, 3, 'subject', 'private-regime@example.test')],
            'provenance' => ['external_receipt' => $external],
            'origin' => ['type' => 'membership', 'id' => 51, 'line_id' => 77],
            'source_type' => 'membership', 'source_id' => 51, 'allocation' => null, 'module_facts' => []];
    }
}
