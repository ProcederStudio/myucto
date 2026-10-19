<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\FinancialCloneHashKind;
use MyInvoice\Service\Anonymization\FinancialCloneHashMap;
use MyInvoice\Service\Anonymization\TaxSubmissionTransportClass;
use MyInvoice\Service\Anonymization\TaxSubmissionTransportCloneCapture;
use MyInvoice\Service\Anonymization\TaxSubmissionTransportCloneScrubber;
use PDO;
use PHPUnit\Framework\TestCase;

final class TaxSubmissionTransportCloneCaptureTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private ?TaxSubmissionTransportCloneCapture $capture = null;
    private string $token;

    protected function setUp(): void
    {
        $this->db = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        $this->pdo = $this->db->pdo();
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->token = hash('sha256', 'transport-capture-run');
        $this->createTables();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->capture?->drop();
        foreach (['anonymized_clone_submission_provenance','tax_submissions','submission_outbox_attempts','submission_outbox','tax_submission_artifacts','tax_submission_status_events','tax_submission_attempts','__transport_links_fixture'] as $table) {
            $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
        }
        $this->db->close();
    }

    public function testCaptureClassifiesEveryTypedTransportRowAndDetectsDrift(): void
    {
        $this->seedTransport();

        $this->capture = TaxSubmissionTransportCloneCapture::capture(
            $this->pdo,
            '__transport_links_fixture',
            $this->token,
        );
        $rows = iterator_to_array($this->capture->receiptPages(2), false);

        self::assertCount(3, $rows);
        self::assertSame(TaxSubmissionTransportClass::SOURCE_PROVENANCE->value, $rows[0]['transport_class']);
        self::assertSame([1, 1, 1, 1, 1], array_map('intval', [
            $rows[0]['attempt_count'], $rows[0]['status_event_count'], $rows[0]['artifact_count'],
            $rows[0]['outbox_count'], $rows[0]['outbox_attempt_count'],
        ]));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $rows[0]['transport_receipt_root']);
        self::assertSame(TaxSubmissionTransportClass::CANCELLED_BEFORE_DISPATCH->value, $rows[1]['transport_class']);
        self::assertSame(TaxSubmissionTransportClass::NO_TRANSPORT->value, $rows[2]['transport_class']);
        self::assertSame(3, $this->capture->count());
        $this->capture->assertCurrentProvenance();

        $this->pdo->exec("UPDATE tax_submission_attempts SET request_sha256='" . str_repeat('0', 64) . "' WHERE id=1001");
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('změnil');
        $this->capture->assertCurrentProvenance();
    }

    public function testFinalizePersistsSourceProvenanceAndDetachesOnlyPendingWorkAtomically(): void
    {
        [$sourceA, $sourceB, $sourceC] = $this->seedTransport();
        $this->capture = TaxSubmissionTransportCloneCapture::capture($this->pdo, '__transport_links_fixture', $this->token);
        $map = FinancialCloneHashMap::create($this->pdo);
        $targetA = hash('sha256', 'accepted-target');
        $targetB = hash('sha256', 'prepared-target');
        $targetC = hash('sha256', 'no-transport-target');
        $this->pdo->prepare('INSERT INTO tax_submissions VALUES (7,101,?),(7,102,?),(7,103,?)')
            ->execute([$targetA, $targetB, $targetC]);

        try {
            $this->pdo->beginTransaction();
            $map->record(FinancialCloneHashKind::ARCHIVE_XML, 101, $sourceA, $targetA);
            $map->record(FinancialCloneHashKind::ARCHIVE_XML, 102, $sourceB, $targetB);
            $map->record(FinancialCloneHashKind::ARCHIVE_XML, 103, $sourceC, $targetC);
            $root = (new TaxSubmissionTransportCloneScrubber($this->pdo))->finalize($this->capture, $map);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $root);
            self::assertSame('submitted', $this->pdo->query('SELECT status FROM tax_submission_attempts WHERE id=1001')->fetchColumn());
            self::assertSame('cancelled', $this->pdo->query('SELECT status FROM tax_submission_attempts WHERE id=1002')->fetchColumn());
            self::assertSame('delivered', $this->pdo->query('SELECT dispatch_state FROM submission_outbox WHERE id=1301')->fetchColumn());
            self::assertSame('cancelled', $this->pdo->query('SELECT dispatch_state FROM submission_outbox WHERE id=1302')->fetchColumn());
            self::assertSame('REMOTE-A', $this->pdo->query('SELECT remote_submission_ref FROM tax_submission_attempts WHERE id=1001')->fetchColumn());
            self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM anonymized_clone_submission_provenance')->fetchColumn());
            $this->pdo->rollBack();

            self::assertSame('prepared', $this->pdo->query('SELECT status FROM tax_submission_attempts WHERE id=1002')->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM anonymized_clone_submission_provenance')->fetchColumn());
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $map->drop();
        }
    }

    public function testCaptureRejectsOneSubmissionLinkedToConflictingArchiveXml(): void
    {
        [$sourceA] = $this->seedTransport();
        $this->pdo->prepare('INSERT INTO __transport_links_fixture VALUES (?,?,?,?,?)')
            ->execute([$this->token, 999, 101, 7, hash('sha256', $sourceA . '-forged')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nejednoznačná');
        TaxSubmissionTransportCloneCapture::capture($this->pdo, '__transport_links_fixture', $this->token);
    }

    public function testCapturePagesGrowingMixedSupplierTransportHistoryThroughTenantIndexes(): void
    {
        $link = $this->pdo->prepare('INSERT INTO __transport_links_fixture VALUES (?,?,?,?,?)');
        $attempt = $this->pdo->prepare('INSERT INTO tax_submission_attempts VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        for ($offset = 1; $offset <= 1001; $offset++) {
            $supplierId = $offset % 2 === 0 ? 7 : 8;
            $submissionId = 10_000 + $offset;
            $sourceHash = hash('sha256', 'growing-source-' . $offset);
            $link->execute([$this->token, 20_000 + $offset, $submissionId, $supplierId, $sourceHash]);
            $attempt->execute([
                30_000 + $offset, $supplierId, $submissionId, 'epo_assisted', 'production', 'prepared',
                'growing-' . $offset, hash('sha256', 'growing-request-' . $offset), null, null, null, null,
                '2099-02-01 00:00:00', null, null,
            ]);
        }

        $this->capture = TaxSubmissionTransportCloneCapture::capture($this->pdo, '__transport_links_fixture', $this->token);
        $count = 0;
        $last = null;
        foreach ($this->capture->receiptPages(500) as $row) {
            $count++;
            $last = $row;
            self::assertSame(TaxSubmissionTransportClass::CANCELLED_BEFORE_DISPATCH->value, $row['transport_class']);
        }
        self::assertSame(1001, $count);
        self::assertSame(11001, (int) $last['tax_submission_id']);

        $plan = $this->pdo->query("EXPLAIN SELECT a.id FROM __transport_links_fixture l JOIN tax_submission_attempts a
            ON a.supplier_id=l.supplier_id AND a.tax_submission_id=l.tax_submission_id
            WHERE l.run_token='" . $this->token . "' AND l.tax_submission_id>10000 ORDER BY l.tax_submission_id,a.id LIMIT 500")
            ->fetchAll(PDO::FETCH_ASSOC);
        $attemptPlan = array_values(array_filter($plan, static fn (array $row): bool => $row['table'] === 'a'))[0] ?? null;
        self::assertIsArray($attemptPlan);
        self::assertSame('idx_tsa_submission', $attemptPlan['key']);
        self::assertNotSame('ALL', $attemptPlan['type']);
    }

    private function createTables(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE __transport_links_fixture (
            run_token CHAR(64) NOT NULL,archive_id BIGINT UNSIGNED NOT NULL,tax_submission_id INT UNSIGNED NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,source_xml_sha256 CHAR(64) NOT NULL,
            PRIMARY KEY(run_token,archive_id,tax_submission_id)) ENGINE=InnoDB');
        $this->pdo->exec("CREATE TEMPORARY TABLE tax_submission_attempts (
            id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,tax_submission_id INT UNSIGNED NOT NULL,
            channel VARCHAR(20) NOT NULL,epo_environment VARCHAR(20) NOT NULL,status VARCHAR(40) NOT NULL,
            idempotency_key VARCHAR(64) NOT NULL,request_sha256 CHAR(64) NOT NULL,signing_fingerprint CHAR(64) NULL,
            response_http_status INT NULL,remote_submission_ref VARCHAR(100) NULL,offline_transfer_id VARCHAR(100) NULL,
            requested_at DATETIME NOT NULL,submitted_at DATETIME NULL,next_poll_at DATETIME NULL,
            KEY idx_tsa_submission(supplier_id,tax_submission_id,id)) ENGINE=InnoDB");
        $this->pdo->exec('CREATE TEMPORARY TABLE tax_submission_status_events (
            id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,tax_submission_id INT UNSIGNED NOT NULL,
            attempt_id BIGINT UNSIGNED NOT NULL,epo_environment VARCHAR(20) NOT NULL,event_type VARCHAR(64) NOT NULL,
            status VARCHAR(64) NOT NULL,http_status INT NULL,created_at DATETIME NOT NULL,
            KEY idx_tsse_submission(supplier_id,tax_submission_id,id)) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TEMPORARY TABLE tax_submission_artifacts (
            id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,tax_submission_id INT UNSIGNED NOT NULL,
            attempt_id BIGINT UNSIGNED NULL,document_id BIGINT UNSIGNED NOT NULL,artifact_kind VARCHAR(64) NOT NULL,
            sha256 CHAR(64) NOT NULL,verification_status VARCHAR(32) NOT NULL,created_at DATETIME NOT NULL,
            KEY idx_tart_submission(supplier_id,tax_submission_id,id)) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TEMPORARY TABLE submission_outbox (
            id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,environment VARCHAR(20) NOT NULL,
            channel VARCHAR(20) NOT NULL,dispatch_mode VARCHAR(20) NOT NULL,agenda_code VARCHAR(48) NOT NULL,
            artifact_kind VARCHAR(32) NOT NULL DEFAULT \'tax_submission\',artifact_id BIGINT UNSIGNED NOT NULL,
            artifact_sha256 CHAR(64) NOT NULL,dispatch_state VARCHAR(32) NOT NULL,acceptance_state VARCHAR(32) NOT NULL,
            correlation_reference VARCHAR(64) NOT NULL,external_message_id VARCHAR(64) NULL,receipt_document_id BIGINT UNSIGNED NULL,
            receipt_signature_status VARCHAR(20) NOT NULL,receipt_matched_by VARCHAR(40) NULL,
            receipt_inbox_message_id BIGINT UNSIGNED NULL,receipt_attached_at DATETIME NULL,created_at DATETIME NOT NULL,
            KEY idx_submission_outbox_artifact(supplier_id,artifact_kind,artifact_id,id)) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TEMPORARY TABLE submission_outbox_attempts (
            id BIGINT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,outbox_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(20) NOT NULL,attempt_no INT UNSIGNED NOT NULL,outcome VARCHAR(32) NOT NULL,
            request_sha256 CHAR(64) NOT NULL,correlation_reference VARCHAR(64) NOT NULL,external_message_id VARCHAR(64) NULL,
            started_at DATETIME NOT NULL,finished_at DATETIME NULL,KEY idx_submission_outbox_attempts_outbox(outbox_id,id)) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TEMPORARY TABLE tax_submissions (
            supplier_id INT UNSIGNED NOT NULL,id INT UNSIGNED NOT NULL,xml_sha256 CHAR(64) NOT NULL,
            PRIMARY KEY(supplier_id,id)) ENGINE=InnoDB');
        $this->pdo->exec("CREATE TEMPORARY TABLE anonymized_clone_submission_provenance (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,tax_submission_id INT UNSIGNED NOT NULL,
            source_xml_sha256 CHAR(64) NOT NULL,target_xml_sha256 CHAR(64) NOT NULL,transport_receipt_root CHAR(64) NOT NULL,
            transport_class VARCHAR(32) NOT NULL,attempt_count INT UNSIGNED NOT NULL,status_event_count INT UNSIGNED NOT NULL,
            artifact_count INT UNSIGNED NOT NULL,outbox_count INT UNSIGNED NOT NULL,outbox_attempt_count INT UNSIGNED NOT NULL,
            UNIQUE KEY uq_submission(supplier_id,tax_submission_id)) ENGINE=InnoDB");
    }

    /** @return array{string,string,string} */
    private function seedTransport(): array
    {
        $sourceA = hash('sha256', 'accepted-source');
        $sourceB = hash('sha256', 'prepared-source');
        $sourceC = hash('sha256', 'no-transport-source');
        $links = $this->pdo->prepare('INSERT INTO __transport_links_fixture VALUES (?,?,?,?,?)');
        $links->execute([$this->token, 1, 101, 7, $sourceA]);
        $links->execute([$this->token, 2, 102, 7, $sourceB]);
        $links->execute([$this->token, 3, 103, 7, $sourceC]);
        $this->pdo->exec("INSERT INTO tax_submission_attempts VALUES
            (1001,7,101,'epo_direct','production','submitted','idem-a','" . hash('sha256', 'request-a') . "','fingerprint',200,'REMOTE-A','TRANSFER-A','2099-01-01 00:00:00','2099-01-01 00:01:00','2099-01-02 00:00:00'),
            (1002,7,102,'epo_assisted','production','prepared','idem-b','" . hash('sha256', 'request-b') . "',NULL,NULL,NULL,NULL,'2099-01-02 00:00:00',NULL,NULL)");
        $this->pdo->exec("INSERT INTO tax_submission_status_events VALUES (1101,7,101,1001,'production','submitted','submitted',200,'2099-01-01 00:01:00')");
        $this->pdo->exec("INSERT INTO tax_submission_artifacts VALUES
            (1201,7,101,1001,9001,'confirmation_p7s','" . hash('sha256', 'confirmation') . "','valid','2099-01-01 00:02:00'),
            (1202,7,102,1002,9002,'source_xml','{$sourceB}','valid','2099-01-02 00:02:00')");
        $this->pdo->exec("INSERT INTO submission_outbox VALUES
            (1301,7,'production','isds','channel','dppo','tax_submission',101,'" . hash('sha256', 'outbox-a') . "','delivered','accepted','CORR-A','EXTERNAL-A',9100,'trusted','external_message_id',9200,'2099-01-01 00:04:00','2099-01-01 00:03:00'),
            (1302,7,'production','epo','channel','dppo','tax_submission',102,'{$sourceB}','ready','unknown','CORR-B',NULL,NULL,'unverified',NULL,NULL,NULL,'2099-01-02 00:03:00')");
        $this->pdo->exec("INSERT INTO submission_outbox_attempts VALUES
            (1401,7,1301,'isds',1,'sent','" . hash('sha256', 'outbox-request') . "','CORR-A','EXTERNAL-A','2099-01-01 00:03:00','2099-01-01 00:04:00')");
        return [$sourceA, $sourceB, $sourceC];
    }
}
