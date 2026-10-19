<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Anonymization\Nonprofit\J6CloneAssessmentCapture;
use MyInvoice\Service\Anonymization\Nonprofit\J6CloneAssessmentCas;
use MyInvoice\Service\Anonymization\Nonprofit\J6CloneAssessmentGraphRebaser;
use MyInvoice\Service\Anonymization\Nonprofit\J6CloneTypedTransformer;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\HistoricJ6SourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationPageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DecisionContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricProviderFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ImmutableManifestSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceEvidencePageHasher;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\J6SourceManifestMaterializer;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;
use PDO;
use PHPUnit\Framework\TestCase;

final class J6CloneAssessmentGraphRebaserTest extends TestCase
{
    public const int SUPPLIER = 91_001;
    public const int PERIOD = 92_001;
    public const int ASSESSMENT = 93_001;

    private Config $baseConfig;
    private PDO $server;
    private Connection $db;
    private PDO $pdo;
    private string $schema;

    protected function setUp(): void
    {
        $this->baseConfig = Config::load(Bootstrap::rootDir());
        self::assertContains($this->baseConfig->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->baseConfig->get('db.name'));
        self::assertStringEndsWith('_test', (string) $this->baseConfig->get('db.name'));
        $this->schema = 'myucto_j6_clone_' . bin2hex(random_bytes(6)) . '_test';
        $this->server = new PDO('mysql:host=' . $this->baseConfig->get('db.host') . ';port=' .
            $this->baseConfig->get('db.port', 3306) . ';charset=utf8mb4',
            (string) $this->baseConfig->get('db.user'), (string) $this->baseConfig->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->server->exec('CREATE DATABASE ' . DatabaseCloner::quote($this->schema) .
            ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        foreach ($this->tables() as $table) {
            $this->server->exec('CREATE TABLE ' . DatabaseCloner::quote($this->schema) . '.' . DatabaseCloner::quote($table)
                . ' LIKE ' . DatabaseCloner::quote((string) $this->baseConfig->get('db.name')) . '.' . DatabaseCloner::quote($table));
        }
        $data = $this->baseConfig->all();
        $data['db']['name'] = $this->schema;
        $this->db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $this->baseConfig->dataDir())));
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
        self::assertMatchesRegularExpression('/^myucto_j6_clone_[a-f0-9]{12}_test$/D', $this->schema);
        $this->server->exec('DROP DATABASE IF EXISTS ' . DatabaseCloner::quote($this->schema));
    }

    public function testRebasePublishesVerifiedGraphWithFinancialParityAndSavepointRollback(): void
    {
        [$receipt, $decisionHeader] = $this->originalGraph();
        $requestHash = hash('sha256', 'synthetic-request');
        $this->insertAssessment($receipt, $decisionHeader, $requestHash);
        $capture = new J6CloneAssessmentCapture(self::ASSESSMENT, 'paged_v2',
            NonprofitTaxRules::canonicalJson($receipt->toArray()), $receipt->snapshotInputHash, $requestHash, $receipt->summary);
        $repository = new NonprofitTaxPagedAssessmentRepository($this->db);
        $factory = $this->factory();
        $providers = $factory->create($repository->binding($repository->find(self::SUPPLIER, self::ASSESSMENT)),
            J6ReadMode::HISTORIC);
        $rebaser = $this->rebaser($factory);
        $beforeCounts = $this->manifestCounts();

        $this->pdo->exec('SAVEPOINT j6_clone_rebase');
        $binding = $rebaser->rebase(self::SUPPLIER, $capture, $providers->sources, $providers->decisions, $requestHash);
        self::assertSame(self::ASSESSMENT, $binding->assessmentId);
        $after = $repository->find(self::SUPPLIER, self::ASSESSMENT);
        self::assertSame(NonprofitTaxRules::canonicalJson($receipt->summary),
            NonprofitTaxRules::canonicalJson($after['graph_receipt']->summary));
        self::assertNotSame($receipt->snapshotRootHash, $after['graph_receipt']->snapshotRootHash);
        self::assertNotSame($receipt->rootHashes['decision'], $after['graph_receipt']->rootHashes['decision']);
        $this->pdo->exec('ROLLBACK TO SAVEPOINT j6_clone_rebase');

        self::assertSame(NonprofitTaxRules::canonicalJson($receipt->toArray()), $this->rawReceipt());
        self::assertSame($beforeCounts, $this->manifestCounts());

        $binding = $rebaser->rebase(self::SUPPLIER, $capture, $providers->sources, $providers->decisions, $requestHash);
        $verified = $factory->create($binding, J6ReadMode::REBASED_HISTORIC);
        self::assertStringNotContainsString('@example.test', $verified->decisions->header()['profile']['evidence']);
        self::assertSame(NonprofitTaxRules::canonicalJson($receipt->summary),
            NonprofitTaxRules::canonicalJson($repository->find(self::SUPPLIER, self::ASSESSMENT)['graph_receipt']->summary));

        $this->expectException(\UnexpectedValueException::class);
        $rebaser->rebase(self::SUPPLIER, $capture, $providers->sources, $providers->decisions, $requestHash);
    }

    private function originalGraph(): array
    {
        $snapshotHasher = new NonprofitTaxSnapshotHasher();
        $snapshotRepository = new NonprofitTaxSnapshotManifestRepository($this->db, $snapshotHasher);
        $materializer = new J6SourceManifestMaterializer($this->db, $snapshotHasher, $snapshotRepository);
        $snapshot = $materializer->materialize($this->header(),
            [new SnapshotPageV2([$this->sourceRow()], [], null, null, null, 1)]);
        $source = new HistoricJ6SourceProvider($snapshotRepository, $snapshotHasher,
            new J6ReadContext(self::SUPPLIER, self::PERIOD, J6ReadMode::HISTORIC, self::ASSESSMENT, $snapshot->id));
        $decisionHeader = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'private-owner@example.test'],
            'source_input_hash' => $snapshot->inputHash, 'draft_id' => 17, 'draft_revision' => 1];
        $canonical = new CanonicalJsonSpool();
        $calculation = (new J6AuthoritativeCalculator())->calculate($source,
            $this->decisions($snapshot->inputHash, $decisionHeader),
            new J6ImmutableManifestSink(new NonprofitTaxCalculationManifestRepository($this->db),
                new J6CalculationPageHasher($canonical), new NonprofitTaxSourceEvidenceManifestRepository($this->db),
                new J6SourceEvidencePageHasher($canonical)));
        return [J6AssessmentGraphReceipt::fromCalculation($snapshot, 0, $calculation), $decisionHeader];
    }

    private function decisions(string $sourceHash, array $header): J6AssessmentDecisionProvider
    {
        return new class ($sourceHash, $header) implements J6AssessmentDecisionProvider {
            public function __construct(private string $sourceHash, private array $header) {}
            public function context(): J6DecisionContext { return new J6DecisionContext(
                J6CloneAssessmentGraphRebaserTest::SUPPLIER, J6CloneAssessmentGraphRebaserTest::PERIOD,
                J6ReadMode::HISTORIC, $this->sourceHash, assessmentId: J6CloneAssessmentGraphRebaserTest::ASSESSMENT,
                decisionManifestId: 1); }
            public function header(): array { return $this->header; }
            public function decisionPages(int $limit = 500): iterable { yield [['source_id' => 'line:77',
                'payload' => ['title' => 'rent', 'evidence' => 'private-decision@example.test',
                    'deductible_cents' => 50010, 'legal_basis' => 'zdp_24_1'], 'record_hash' => hash('sha256', 'decision')]]; }
            public function activityPages(int $limit = 500): iterable { yield [['activity_kind_id' => '2',
                'payload' => ['evidence' => 'private-activity@example.test'], 'record_hash' => hash('sha256', 'activity')]]; }
            public function fundingPoolPages(int $limit = 500): iterable { yield []; }
            public function fundingMemberPages(int $limit = 500): iterable { yield []; }
            public function fundingLinkPages(int $limit = 500): iterable { yield []; }
        };
    }

    private function insertAssessment(J6AssessmentGraphReceipt $receipt, array $decisionHeader, string $requestHash): void
    {
        $snapshot = ['schema' => 'nonprofit-j6-snapshot-reference.v1', 'manifest_id' => $receipt->snapshotManifestId,
            'input_hash' => $receipt->snapshotInputHash, 'root_hash' => $receipt->snapshotRootHash];
        $assessment = ['schema' => 'nonprofit-j6-decision-reference.v1', 'header' => $decisionHeader,
            'manifest_id' => $receipt->manifestIds['decision'], 'root_hash' => $receipt->rootHashes['decision']];
        $result = ['schema' => 'nonprofit-j6-result-reference.v1', 'summary' => $receipt->summary,
            'manifest_id' => $receipt->manifestIds['result'], 'root_hash' => $receipt->rootHashes['result'],
            'coverage_manifest_id' => $receipt->manifestIds['coverage'], 'coverage_root_hash' => $receipt->rootHashes['coverage'],
            'usage_manifest_id' => $receipt->manifestIds['usage'], 'usage_root_hash' => $receipt->rootHashes['usage']];
        $stmt = $this->pdo->prepare('INSERT INTO nonprofit_tax_assessments
            (id,supplier_id,period_id,revision_no,previous_id,status,reason,ruleset,storage_version,input_hash,request_hash,
             idempotency_key,snapshot_json,assessment_json,result_json,created_by,source_manifest_id,
             funding_reference_manifest_id,funding_history_manifest_id,decision_manifest_id,result_manifest_id,
             coverage_manifest_id,usage_manifest_id,calculation_input_hash,result_root_hash,coverage_root_hash,summary_json,j6_receipt_json)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([self::ASSESSMENT, self::SUPPLIER, self::PERIOD, 1, null, 'confirmed', 'Synthetic reason',
            $receipt->ruleset, 'paged_v2', $receipt->snapshotInputHash, $requestHash, 'synthetic-clone',
            NonprofitTaxRules::canonicalJson($snapshot), NonprofitTaxRules::canonicalJson($assessment),
            NonprofitTaxRules::canonicalJson($result), 7, $receipt->snapshotManifestId,
            $receipt->evidenceManifestIds['funding_reference'], $receipt->evidenceManifestIds['funding_history'],
            $receipt->manifestIds['decision'], $receipt->manifestIds['result'], $receipt->manifestIds['coverage'],
            $receipt->manifestIds['usage'], $receipt->calculationInputHash, $receipt->rootHashes['result'],
            $receipt->rootHashes['coverage'], NonprofitTaxRules::canonicalJson($receipt->summary),
            NonprofitTaxRules::canonicalJson($receipt->toArray())]);
    }

    private function rebaser(J6HistoricProviderFactory $factory): J6CloneAssessmentGraphRebaser
    {
        $canonical = new CanonicalJsonSpool();
        $calculationHasher = new J6CalculationPageHasher($canonical);
        $evidenceHasher = new J6SourceEvidencePageHasher($canonical);
        $calculator = new J6AuthoritativeCalculator();
        $cas = new J6CloneAssessmentCas($this->db, $factory, $calculator, $calculationHasher, $evidenceHasher);
        return new J6CloneAssessmentGraphRebaser($this->db,
            new J6CloneTypedTransformer(new TextScrubber(new Pseudonymizer(hash('sha256', 'j6-clone-integration', true)))),
            new J6SourceManifestMaterializer($this->db, new NonprofitTaxSnapshotHasher(),
                new NonprofitTaxSnapshotManifestRepository($this->db, new NonprofitTaxSnapshotHasher())),
            new NonprofitTaxSnapshotManifestRepository($this->db, new NonprofitTaxSnapshotHasher()),
            new NonprofitTaxSnapshotHasher(), $calculator,
            new NonprofitTaxCalculationManifestRepository($this->db), $calculationHasher,
            new NonprofitTaxSourceEvidenceManifestRepository($this->db), $evidenceHasher, $cas);
    }

    private function factory(): J6HistoricProviderFactory
    {
        $canonical = new CanonicalJsonSpool();
        return new J6HistoricProviderFactory(new NonprofitTaxSnapshotManifestRepository($this->db,
            new NonprofitTaxSnapshotHasher()),
            new NonprofitTaxSnapshotHasher(), new NonprofitTaxCalculationManifestRepository($this->db),
            new J6CalculationPageHasher($canonical), new NonprofitTaxSourceEvidenceManifestRepository($this->db),
            new J6SourceEvidencePageHasher($canonical));
    }

    private function header(): SnapshotHeaderV2
    {
        return new SnapshotHeaderV2(['schema' => SnapshotHeaderV2::SCHEMA, 'scope' => 'current-period-source',
            'supplier_id' => self::SUPPLIER, 'period_id' => self::PERIOD,
            'period' => ['id' => self::PERIOD, 'fiscal_year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'],
            'frameworks' => ['cz_504'], 'ruleset' => NonprofitTaxRules::VERSION,
            'funding_history_mode' => 'paged_assessment_graph_v1', 'source_fact_schemas' => []]);
    }

    private function sourceRow(): array
    {
        $axis = static fn (int $type, int $value, string $semantic, string $name): array => [
            'type_id' => $type, 'value_id' => $value, 'semantic_key' => $semantic, 'code' => $name, 'name' => $name,
            'assignment_origin' => 'direct', 'assignment_origin_ref' => 'line:77'];
        return ['id' => 'line:77', 'line_id' => 77, 'entry_id' => 70, 'line_no' => 1, 'account_id' => 60,
            'account_code' => '602100', 'date' => '2026-10-19', 'kind' => 'revenue', 'amount_cents' => 50010,
            'activity' => 'main', 'activity_kind_id' => 2, 'tax_regime' => 'subject',
            'mandatory_non_deductible' => false, 'neutral' => false,
            'axes' => ['activity' => $axis(1, 1, 'main', 'private-axis@example.test'),
                'activity_kind' => $axis(2, 2, 'membership', 'private-kind@example.test'),
                'tax_regime' => $axis(3, 3, 'subject', 'private-regime@example.test')],
            'provenance' => ['external_receipt' => ['version' => 1,
                'payload' => ['email' => 'external-owner@example.test'], 'hash' => hash('sha256', 'external')]],
            'origin' => ['type' => 'synthetic', 'id' => 51, 'line_id' => 77],
            'source_type' => 'synthetic', 'source_id' => 51, 'allocation' => null, 'module_facts' => []];
    }

    private function rawReceipt(): string
    {
        $stmt = $this->pdo->prepare('SELECT j6_receipt_json FROM nonprofit_tax_assessments WHERE supplier_id=? AND id=?');
        $stmt->execute([self::SUPPLIER, self::ASSESSMENT]);
        return (string) $stmt->fetchColumn();
    }

    private function manifestCounts(): array
    {
        $counts = [];
        foreach (array_slice($this->tables(), 0, 9) as $table) {
            $counts[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . DatabaseCloner::quote($table))->fetchColumn();
        }
        return $counts;
    }

    private function tables(): array
    {
        return ['nonprofit_tax_snapshot_chunks', 'nonprofit_tax_snapshot_manifests',
            'nonprofit_tax_snapshot_manifest_chunks', 'nonprofit_tax_calculation_chunks',
            'nonprofit_tax_calculation_manifests', 'nonprofit_tax_calculation_manifest_chunks',
            'nonprofit_tax_source_evidence_chunks', 'nonprofit_tax_source_evidence_manifests',
            'nonprofit_tax_source_evidence_manifest_chunks', 'nonprofit_tax_assessments'];
    }
}
