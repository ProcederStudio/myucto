<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Anonymization\FinancialArchiveCloneCoordinator;
use MyInvoice\Service\Anonymization\FinancialArchiveProtectedTable;
use PDO;
use PHPUnit\Framework\TestCase;

final class FinancialArchiveCloneCoordinatorTest extends TestCase
{
    private Config $baseConfig;

    private PDO $server;

    private Connection $connection;

    private string $schema;

    protected function setUp(): void
    {
        $this->baseConfig = Config::load(Bootstrap::rootDir());
        self::assertContains($this->baseConfig->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        self::assertStringEndsWith('_test', (string) $this->baseConfig->get('db.name'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->baseConfig->get('db.name'));
        $this->schema = 'myucto_archive_capture_' . bin2hex(random_bytes(6)) . '_test';
        $this->server = new PDO(
            'mysql:host=' . $this->baseConfig->get('db.host') . ';port=' . $this->baseConfig->get('db.port', 3306) . ';charset=utf8mb4',
            (string) $this->baseConfig->get('db.user'),
            (string) $this->baseConfig->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->server->exec('CREATE DATABASE ' . DatabaseCloner::quote($this->schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->server->exec('USE ' . DatabaseCloner::quote($this->schema));
        $this->server->exec('CREATE TABLE income_tax_returns (id INT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, year SMALLINT UNSIGNED NOT NULL, taxpayer_type VARCHAR(2) NOT NULL, status VARCHAR(16) NOT NULL, final_snapshot_id BIGINT UNSIGNED NULL, computed LONGTEXT NULL)');
        $this->server->exec('CREATE TABLE income_tax_return_snapshots (id BIGINT UNSIGNED PRIMARY KEY, tax_return_id INT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL, snapshot_json JSON NOT NULL, source_manifest JSON NOT NULL, source_sha256 CHAR(64) NOT NULL, xml_content LONGTEXT NOT NULL, xml_sha256 CHAR(64) NOT NULL, UNIQUE KEY uq_dppo_snapshot_tenant (supplier_id,id), KEY idx_itr_snapshot_supplier_xml (supplier_id,xml_sha256,id))');
        $this->server->exec('CREATE TABLE tax_submissions (id INT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, form_code VARCHAR(20) NOT NULL, period_year SMALLINT UNSIGNED NOT NULL, xml_content LONGTEXT NOT NULL, xml_sha256 CHAR(64) NOT NULL, KEY idx_tax_submission_archive_xml (supplier_id,form_code,period_year,xml_sha256,id))');
        $this->server->exec('CREATE TABLE tax_submission_attempts (id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,tax_submission_id INT UNSIGNED NOT NULL,channel VARCHAR(20) NOT NULL,epo_environment VARCHAR(20) NOT NULL,status VARCHAR(40) NOT NULL,idempotency_key VARCHAR(64) NOT NULL,request_sha256 CHAR(64) NOT NULL,signing_fingerprint CHAR(64) NULL,response_http_status INT NULL,remote_submission_ref VARCHAR(100) NULL,offline_transfer_id VARCHAR(100) NULL,requested_at DATETIME NOT NULL,submitted_at DATETIME NULL,KEY idx_tsa_submission(supplier_id,tax_submission_id,id))');
        $this->server->exec('CREATE TABLE tax_submission_status_events (id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,tax_submission_id INT UNSIGNED NOT NULL,attempt_id BIGINT UNSIGNED NOT NULL,epo_environment VARCHAR(20) NOT NULL,event_type VARCHAR(64) NOT NULL,status VARCHAR(64) NOT NULL,http_status INT NULL,created_at DATETIME NOT NULL,KEY idx_tsse_submission(supplier_id,tax_submission_id,id))');
        $this->server->exec('CREATE TABLE tax_submission_artifacts (id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,tax_submission_id INT UNSIGNED NOT NULL,attempt_id BIGINT UNSIGNED NULL,document_id BIGINT UNSIGNED NOT NULL,artifact_kind VARCHAR(64) NOT NULL,sha256 CHAR(64) NOT NULL,verification_status VARCHAR(32) NOT NULL,created_at DATETIME NOT NULL,KEY idx_tart_submission(supplier_id,tax_submission_id,id))');
        $this->server->exec("CREATE TABLE submission_outbox (id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,environment VARCHAR(20) NOT NULL,channel VARCHAR(20) NOT NULL,dispatch_mode VARCHAR(20) NOT NULL,agenda_code VARCHAR(48) NOT NULL,artifact_kind VARCHAR(32) NOT NULL DEFAULT 'tax_submission',artifact_id BIGINT UNSIGNED NOT NULL,artifact_sha256 CHAR(64) NOT NULL,dispatch_state VARCHAR(32) NOT NULL,acceptance_state VARCHAR(32) NOT NULL,correlation_reference VARCHAR(64) NOT NULL,external_message_id VARCHAR(64) NULL,receipt_document_id BIGINT UNSIGNED NULL,receipt_signature_status VARCHAR(20) NOT NULL DEFAULT 'unverified',receipt_matched_by VARCHAR(40) NULL,receipt_inbox_message_id BIGINT UNSIGNED NULL,receipt_attached_at DATETIME NULL,created_at DATETIME NOT NULL,KEY idx_submission_outbox_artifact(supplier_id,artifact_kind,artifact_id,id))");
        $this->server->exec('CREATE TABLE submission_outbox_attempts (id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,outbox_id BIGINT UNSIGNED NOT NULL,channel VARCHAR(20) NOT NULL,attempt_no INT UNSIGNED NOT NULL,outcome VARCHAR(32) NOT NULL,request_sha256 CHAR(64) NOT NULL,correlation_reference VARCHAR(64) NOT NULL,external_message_id VARCHAR(64) NULL,started_at DATETIME NOT NULL,finished_at DATETIME NULL,KEY idx_submission_outbox_attempts_outbox(outbox_id,id))');
        $this->server->exec('CREATE TABLE nonprofit_legal_profiles (id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, period_id BIGINT UNSIGNED NOT NULL, revision_no INT UNSIGNED NOT NULL, previous_id BIGINT UNSIGNED NULL, profile_json LONGTEXT NOT NULL, module_proof_json LONGTEXT NOT NULL, reason TEXT NOT NULL, idempotency_key VARCHAR(80) NOT NULL, request_hash CHAR(64) NOT NULL, record_hash CHAR(64) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, UNIQUE KEY uq_nlp_tenant (supplier_id,id), UNIQUE KEY uq_nlp_revision (supplier_id,period_id,revision_no))');
        $this->server->exec('CREATE TABLE nonprofit_tax_saving_sources (id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, period_id BIGINT UNSIGNED NOT NULL, snapshot_id BIGINT UNSIGNED NOT NULL, legal_profile_id BIGINT UNSIGNED NOT NULL, reduction_kc BIGINT UNSIGNED NOT NULL, saving_cents BIGINT UNSIGNED NOT NULL, basis_json LONGTEXT NOT NULL, record_hash CHAR(64) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, UNIQUE KEY uq_ntss_tenant (supplier_id,id))');
        $this->server->exec('CREATE TABLE nonprofit_tax_saving_commands (id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, idempotency_key VARCHAR(80) NOT NULL, request_json LONGTEXT NOT NULL, request_hash CHAR(64) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, UNIQUE KEY uq_ntsc_tenant (supplier_id,id))');
        $this->server->exec("CREATE TABLE nonprofit_tax_saving_events (id BIGINT UNSIGNED PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL, command_id BIGINT UNSIGNED NOT NULL, previous_id BIGINT UNSIGNED NULL, original_id BIGINT UNSIGNED NULL, operation ENUM('use','reverse_use','void','restore') NOT NULL, period_id BIGINT UNSIGNED NULL, cost_line_id BIGINT UNSIGNED NULL, amount_cents BIGINT UNSIGNED NOT NULL, parent_hash CHAR(64) NOT NULL, proof_json LONGTEXT NOT NULL, receipt_json LONGTEXT NOT NULL, record_hash CHAR(64) NOT NULL, UNIQUE KEY uq_ntse_tenant (supplier_id,id), UNIQUE KEY uq_ntse_source (supplier_id,source_id,id))");
        $this->server->exec('CREATE TABLE nonprofit_tax_saving_cost_bindings (supplier_id INT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL, cost_line_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (supplier_id,event_id))');
        $data = $this->baseConfig->all();
        $data['db']['name'] = $this->schema;
        $this->connection = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $this->baseConfig->dataDir())));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        self::assertMatchesRegularExpression('/^myucto_archive_capture_[a-f0-9]{12}_test$/D', $this->schema);
        $this->server->exec('DROP DATABASE IF EXISTS ' . DatabaseCloner::quote($this->schema));
    }

    public function testCaptureStagesExactArchiveAndEveryMatchingSubmissionWithoutAnnualArrays(): void
    {
        $xml = '<Pisemnost synthetic="1"/>';
        $manifest = '{"synthetic":true}';
        $snapshot = json_encode([
            'podklady' => [
                'accounting_decree' => '504',
                'nonprofit_calculation' => ['version' => 3, 'data' => ['period' => ['fiscal_year' => 2099]]],
            ],
        ], JSON_THROW_ON_ERROR);
        $other = json_encode(['podklady' => ['accounting_decree' => '500']], JSON_THROW_ON_ERROR);
        $this->server->exec("INSERT INTO income_tax_returns VALUES (10,7,2099,'po','final',100,'{\"tax\":1}'),(11,7,2099,'po','final',101,'{\"tax\":2}')");
        $insert = $this->server->prepare('INSERT INTO income_tax_return_snapshots VALUES (?,?,?,?,?,?,?,?)');
        $insert->execute([99, 10, 7, $snapshot, $manifest, hash('sha256', $manifest), '<Pisemnost historic="1"/>', hash('sha256', '<Pisemnost historic="1"/>')]);
        $insert->execute([100, 10, 7, $snapshot, $manifest, hash('sha256', $manifest), $xml, hash('sha256', $xml)]);
        $insert->execute([101, 11, 7, $other, '{}', hash('sha256', '{}'), '<x/>', hash('sha256', '<x/>')]);
        $submission = $this->server->prepare('INSERT INTO tax_submissions VALUES (?,?,?,?,?,?)');
        $submission->execute([199, 7, 'dppdp9', 2099, '<Pisemnost historic="1"/>', hash('sha256', '<Pisemnost historic="1"/>')]);
        $submission->execute([200, 7, 'dppdp9', 2099, $xml, hash('sha256', $xml)]);
        $submission->execute([201, 7, 'dppdp9', 2099, $xml, hash('sha256', $xml)]);
        $submission->execute([202, 8, 'dppdp9', 2099, $xml, hash('sha256', $xml)]);

        $capture = (new FinancialArchiveCloneCoordinator())->capture($this->connection);
        try {
            self::assertSame(2, $capture->archiveCount());
            self::assertSame(3, $capture->submissionCount());
            self::assertSame(2, $capture->protectedRows()->count(FinancialArchiveProtectedTable::SNAPSHOT));
            self::assertSame(1, $capture->protectedRows()->count(FinancialArchiveProtectedTable::TAX_RETURN));
            self::assertSame(3, $capture->protectedRows()->count(FinancialArchiveProtectedTable::TAX_SUBMISSION));
            self::assertSame([200, 201], array_map(
                static fn (array $row): int => (int) $row['tax_submission_id'],
                iterator_to_array($capture->submissionPages(100, 1), false),
            ));
            $archives = iterator_to_array($capture->archivePages(1), false);
            self::assertCount(2, $archives);
            self::assertSame(99, $archives[0]->archiveId);
            self::assertSame(1, $archives[0]->submissionCount);
            self::assertFalse($archives[0]->finalSnapshot);
            self::assertNull($archives[0]->computedSha256);
            self::assertSame(100, $archives[1]->archiveId);
            self::assertSame(2, $archives[1]->submissionCount);
            self::assertTrue($archives[1]->finalSnapshot);
            self::assertSame(hash('sha256', '{"tax":1}'), $archives[1]->computedSha256);
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('complete verify');
            $capture->assertComplete();
        } finally {
            $capture->drop();
        }
    }

    public function testCaptureRejectsHashDriftAndCleansOnlyItsStages(): void
    {
        $snapshot = '{"podklady":{"accounting_decree":"504","nonprofit_calculation":{"data":{"period":{"fiscal_year":2099}}}}}';
        $this->server->exec("INSERT INTO income_tax_returns VALUES (10,7,2099,'po','final',100,'{}')");
        $insert = $this->server->prepare('INSERT INTO income_tax_return_snapshots VALUES (?,?,?,?,?,?,?,?)');
        $insert->execute([100, 10, 7, $snapshot, '{}', str_repeat('0', 64), '<x/>', hash('sha256', '<x/>')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('hash');
        (new FinancialArchiveCloneCoordinator())->capture($this->connection);
    }

    public function testCaptureStagesSavingsChainInBoundedTypedGraph(): void
    {
        $snapshot = json_encode([
            'podklady' => [
                'accounting_decree' => '504',
                'nonprofit_calculation' => ['version' => 3, 'data' => ['period' => ['fiscal_year' => 2099]]],
            ],
        ], JSON_THROW_ON_ERROR);
        $manifest = '{"synthetic":"savings-chain"}';
        $xml = '<Pisemnost synthetic-savings="1"/>';
        $this->server->exec("INSERT INTO income_tax_returns VALUES (10,7,2099,'po','final',100,'{}')");
        $insert = $this->server->prepare('INSERT INTO income_tax_return_snapshots VALUES (?,?,?,?,?,?,?,?)');
        $insert->execute([100, 10, 7, $snapshot, $manifest, hash('sha256', $manifest), $xml, hash('sha256', $xml)]);
        $this->server->prepare('INSERT INTO tax_submissions VALUES (?,?,?,?,?,?)')->execute([200, 7, 'dppdp9', 2099, $xml, hash('sha256', $xml)]);
        $basis = json_encode(['snapshot_id' => 100, 'evidence' => 'Synthetic Person'], JSON_THROW_ON_ERROR);
        $sourceHash = hash('sha256', 'synthetic-source-record');
        $this->server->prepare('INSERT INTO nonprofit_legal_profiles VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([500, 7, 40, 1, null, '{"kind":"narrow_vpp"}', '{"synthetic":true}', 'Synthetic reason',
                'synthetic-profile', hash('sha256', 'synthetic-profile-request'), hash('sha256', 'synthetic-profile-record'), 1]);
        $this->server->prepare('INSERT INTO nonprofit_tax_saving_sources VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([300, 7, 40, 100, 500, 1000, 19000, $basis, $sourceHash, 1]);
        $request = json_encode(['source_id' => 300, 'operation' => 'use', 'evidence' => 'Synthetic Person'], JSON_THROW_ON_ERROR);
        $requestHash = hash('sha256', 'synthetic-command-record');
        $this->server->prepare('INSERT INTO nonprofit_tax_saving_commands VALUES (?,?,?,?,?,?)')
            ->execute([400, 7, 'synthetic-command', $request, $requestHash, 1]);
        $proof = json_encode(['evidence' => 'Synthetic Person', 'amount_cents' => '1000'], JSON_THROW_ON_ERROR);
        $receipt = json_encode(['command_hash' => $requestHash], JSON_THROW_ON_ERROR);
        $eventHash = hash('sha256', 'synthetic-event-record');
        $this->server->prepare('INSERT INTO nonprofit_tax_saving_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([600, 7, 300, 400, null, null, 'use', 41, 900, 1000, $sourceHash, $proof, $receipt, $eventHash]);
        $this->server->prepare('INSERT INTO nonprofit_tax_saving_cost_bindings VALUES (?,?,?,?)')->execute([7, 300, 600, 900]);

        $capture = (new FinancialArchiveCloneCoordinator())->capture($this->connection);
        try {
            self::assertSame(1, $capture->savings()->sourceCount());
            self::assertSame(1, $capture->savings()->commandCount());
            self::assertSame(1, $capture->savings()->eventCount());
            self::assertSame(1, $capture->savings()->bindingCount());
            self::assertSame(1, $capture->savings()->profileCount());
            self::assertSame(1, $capture->protectedRows()->count(FinancialArchiveProtectedTable::LEGAL_PROFILE));
            self::assertSame(1, $capture->protectedRows()->count(FinancialArchiveProtectedTable::SAVING_SOURCE));
            self::assertSame(1, $capture->protectedRows()->count(FinancialArchiveProtectedTable::SAVING_COMMAND));
            self::assertSame(1, $capture->protectedRows()->count(FinancialArchiveProtectedTable::SAVING_EVENT));
        } finally {
            $capture->drop();
        }
    }

    public function testCaptureRejectsMissingActiveSavingsBindingAndCleansOwnedStages(): void
    {
        $snapshot = '{"podklady":{"accounting_decree":"504","nonprofit_calculation":{"version":3,"data":{"period":{"fiscal_year":2099}}}}}';
        $manifest = '{"synthetic":"missing-binding"}';
        $xml = '<Pisemnost synthetic-savings="missing-binding"/>';
        $this->server->exec("INSERT INTO income_tax_returns VALUES (10,7,2099,'po','final',100,'{}')");
        $this->server->prepare('INSERT INTO income_tax_return_snapshots VALUES (?,?,?,?,?,?,?,?)')
            ->execute([100, 10, 7, $snapshot, $manifest, hash('sha256', $manifest), $xml, hash('sha256', $xml)]);
        $this->server->prepare('INSERT INTO tax_submissions VALUES (?,?,?,?,?,?)')
            ->execute([200, 7, 'dppdp9', 2099, $xml, hash('sha256', $xml)]);
        $this->server->prepare('INSERT INTO nonprofit_legal_profiles VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([500, 7, 40, 1, null, '{"kind":"narrow_vpp"}', '{"synthetic":true}', 'Missing binding profile',
                'missing-binding-profile', hash('sha256', 'missing-binding-profile-request'), hash('sha256', 'missing-binding-profile-record'), 1]);
        $sourceHash = hash('sha256', 'missing-binding-source');
        $this->server->prepare('INSERT INTO nonprofit_tax_saving_sources VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([300, 7, 40, 100, 500, 1000, 19000, '{"snapshot_id":100}', $sourceHash, 1]);
        $requestHash = hash('sha256', 'missing-binding-command');
        $this->server->prepare('INSERT INTO nonprofit_tax_saving_commands VALUES (?,?,?,?,?,?)')
            ->execute([400, 7, 'missing-binding', '{"source_id":300,"operation":"use"}', $requestHash, 1]);
        $this->server->prepare('INSERT INTO nonprofit_tax_saving_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([600, 7, 300, 400, null, null, 'use', 41, 900, 1000, $sourceHash,
                '{"synthetic":true}', json_encode(['command_hash' => $requestHash], JSON_THROW_ON_ERROR),
                hash('sha256', 'missing-binding-event')]);

        try {
            (new FinancialArchiveCloneCoordinator())->capture($this->connection);
            self::fail('Capture přijal aktivní use event bez cost bindingu.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('řetězci', $e->getMessage());
        }
        $stages = $this->server->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE '__myucto_saving_%' OR TABLE_NAME LIKE '__myucto_legal_profiles_%')")->fetchColumn();
        self::assertSame(0, (int) $stages);
    }

    public function testCapturePagesGrowingHistoryBeyondTwoFullBatches(): void
    {
        $snapshot = json_encode([
            'podklady' => [
                'accounting_decree' => '504',
                'nonprofit_calculation' => ['version' => 3, 'data' => ['period' => ['fiscal_year' => 2099]]],
            ],
        ], JSON_THROW_ON_ERROR);
        $manifest = '{"synthetic":"growing-history"}';
        $return = $this->server->prepare("INSERT INTO income_tax_returns VALUES (?,?,2099,'po','final',?,'{}')");
        $archive = $this->server->prepare('INSERT INTO income_tax_return_snapshots VALUES (?,?,?,?,?,?,?,?)');
        $submission = $this->server->prepare('INSERT INTO tax_submissions VALUES (?,?,?,?,?,?)');
        $profile = $this->server->prepare('INSERT INTO nonprofit_legal_profiles VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $source = $this->server->prepare('INSERT INTO nonprofit_tax_saving_sources VALUES (?,?,?,?,?,?,?,?,?,?)');
        $command = $this->server->prepare('INSERT INTO nonprofit_tax_saving_commands VALUES (?,?,?,?,?,?)');
        $event = $this->server->prepare('INSERT INTO nonprofit_tax_saving_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $binding = $this->server->prepare('INSERT INTO nonprofit_tax_saving_cost_bindings VALUES (?,?,?,?)');
        $this->server->beginTransaction();
        for ($offset = 1; $offset <= 1001; $offset++) {
            $supplierId = 7 + intdiv($offset - 1, 400);
            $returnId = 10_000 + $offset;
            $archiveId = 20_000 + $offset;
            $xml = '<Pisemnost synthetic-history="' . $offset . '"/>';
            $xmlHash = hash('sha256', $xml);
            $return->execute([$returnId, $supplierId, $archiveId]);
            $archive->execute([
                $archiveId,
                $returnId,
                $supplierId,
                $snapshot,
                $manifest,
                hash('sha256', $manifest),
                $xml,
                $xmlHash,
            ]);
            $submission->execute([30_000 + $offset, $supplierId, 'dppdp9', 2099, $xml, $xmlHash]);
            $sourceId = 40_000 + $offset;
            $commandId = 50_000 + $offset;
            $eventId = 60_000 + $offset;
            $costLineId = 70_000 + $offset;
            $profileId = 80_000 + $offset;
            $revision = (($offset - 1) % 400) + 1;
            $previousProfileId = $revision === 1 ? null : $profileId - 1;
            $sourceHash = hash('sha256', 'source:' . $offset);
            $requestHash = hash('sha256', 'command:' . $offset);
            $profile->execute([$profileId, $supplierId, 40, $revision, $previousProfileId,
                json_encode(['kind' => 'narrow_vpp', 'row' => $offset], JSON_THROW_ON_ERROR), '{"synthetic":true}',
                'Synthetic profile ' . $offset, 'synthetic-profile-' . $offset,
                hash('sha256', 'profile-request:' . $offset), hash('sha256', 'profile-record:' . $offset), 1]);
            $source->execute([$sourceId, $supplierId, 40, $archiveId, $profileId, 1000, 19000,
                json_encode(['snapshot_id' => $archiveId, 'row' => $offset], JSON_THROW_ON_ERROR), $sourceHash, 1]);
            $command->execute([$commandId, $supplierId, 'synthetic-' . $offset,
                json_encode(['source_id' => $sourceId, 'operation' => 'use'], JSON_THROW_ON_ERROR), $requestHash, 1]);
            $event->execute([$eventId, $supplierId, $sourceId, $commandId, null, null, 'use', 41, $costLineId,
                1000, $sourceHash, '{"synthetic":true}', json_encode(['command_hash' => $requestHash], JSON_THROW_ON_ERROR),
                hash('sha256', 'event:' . $offset)]);
            $binding->execute([$supplierId, $sourceId, $eventId, $costLineId]);
        }
        $this->server->commit();

        $currentPlan = $this->server->query(
            'EXPLAIN SELECT id,supplier_id FROM income_tax_return_snapshots FORCE INDEX (uq_dppo_snapshot_tenant) '
            . 'WHERE supplier_id=7 AND id>20000 ORDER BY id LIMIT 500',
        )->fetch(PDO::FETCH_ASSOC);
        $nextPlan = $this->server->query(
            'EXPLAIN SELECT id,supplier_id FROM income_tax_return_snapshots FORCE INDEX (uq_dppo_snapshot_tenant) '
            . 'WHERE supplier_id>7 ORDER BY supplier_id,id LIMIT 500',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertContains($currentPlan['type'], ['ref', 'range']);
        self::assertSame('uq_dppo_snapshot_tenant', $currentPlan['key']);
        self::assertStringNotContainsString('filesort', strtolower((string) $currentPlan['Extra']));
        self::assertSame('range', $nextPlan['type']);
        self::assertSame('uq_dppo_snapshot_tenant', $nextPlan['key']);
        self::assertStringNotContainsString('filesort', strtolower((string) $nextPlan['Extra']));
        $savingPlan = $this->server->query(
            'EXPLAIN SELECT id,supplier_id FROM nonprofit_tax_saving_sources FORCE INDEX (uq_ntss_tenant) '
            . 'WHERE supplier_id=7 AND id>40000 ORDER BY id LIMIT 500',
        )->fetch(PDO::FETCH_ASSOC);
        $eventPlan = $this->server->query(
            'EXPLAIN SELECT id,supplier_id,source_id FROM nonprofit_tax_saving_events FORCE INDEX (uq_ntse_source) '
            . 'WHERE supplier_id=7 AND (source_id>40000 OR (source_id=40000 AND id>60000)) ORDER BY source_id,id LIMIT 500',
        )->fetch(PDO::FETCH_ASSOC);
        $profilePlan = $this->server->query(
            'EXPLAIN SELECT id,supplier_id,period_id,revision_no FROM nonprofit_legal_profiles FORCE INDEX (uq_nlp_revision) '
            . 'WHERE supplier_id=7 AND (period_id>40 OR (period_id=40 AND revision_no>1)) ORDER BY period_id,revision_no LIMIT 500',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertContains($savingPlan['type'], ['ref', 'range']);
        self::assertSame('uq_ntss_tenant', $savingPlan['key']);
        self::assertStringNotContainsString('filesort', strtolower((string) $savingPlan['Extra']));
        self::assertContains($eventPlan['type'], ['ref', 'range']);
        self::assertSame('uq_ntse_source', $eventPlan['key']);
        self::assertStringNotContainsString('filesort', strtolower((string) $eventPlan['Extra']));
        self::assertContains($profilePlan['type'], ['ref', 'range']);
        self::assertSame('uq_nlp_revision', $profilePlan['key']);
        self::assertStringNotContainsString('filesort', strtolower((string) $profilePlan['Extra']));

        $capture = (new FinancialArchiveCloneCoordinator())->capture($this->connection);
        try {
            self::assertSame(1001, $capture->archiveCount());
            self::assertSame(1001, $capture->submissionCount());
            self::assertSame(1001, $capture->protectedRows()->count(FinancialArchiveProtectedTable::SNAPSHOT));
            self::assertSame(1001, $capture->protectedRows()->count(FinancialArchiveProtectedTable::TAX_RETURN));
            self::assertSame(1001, $capture->protectedRows()->count(FinancialArchiveProtectedTable::TAX_SUBMISSION));
            self::assertSame(1001, $capture->savings()->sourceCount());
            self::assertSame(1001, $capture->savings()->commandCount());
            self::assertSame(1001, $capture->savings()->eventCount());
            self::assertSame(1001, $capture->savings()->bindingCount());
            self::assertSame(1001, $capture->savings()->profileCount());
            self::assertSame(1001, $capture->protectedRows()->count(FinancialArchiveProtectedTable::LEGAL_PROFILE));
        } finally {
            $capture->drop();
        }
    }
}
