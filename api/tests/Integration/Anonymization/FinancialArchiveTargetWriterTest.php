<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\CapturedFinancialArchive;
use MyInvoice\Service\Anonymization\FinancialArchiveSpool;
use MyInvoice\Service\Anonymization\FinancialArchiveTargetArtifact;
use MyInvoice\Service\Anonymization\FinancialArchiveTargetWriter;
use MyInvoice\Service\Anonymization\FinancialCloneHashMap;
use PDO;
use PHPUnit\Framework\TestCase;

final class FinancialArchiveTargetWriterTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private FinancialCloneHashMap $hashes;
    private string $sourceSnapshot;
    private string $targetSnapshot;
    private string $sourceManifest;
    private string $targetManifest;
    private string $sourceComputed;
    private string $targetComputed;
    private string $sourceXml;
    private string $targetXml;

    protected function setUp(): void
    {
        $this->db = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        $this->pdo = $this->db->pdo();
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        foreach (['income_tax_return_snapshots', 'income_tax_returns', 'tax_submissions'] as $table) {
            $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS ' . $table);
        }
        $this->pdo->exec('CREATE TEMPORARY TABLE income_tax_return_snapshots (
            id BIGINT UNSIGNED PRIMARY KEY,tax_return_id INT UNSIGNED NOT NULL,supplier_id INT UNSIGNED NOT NULL,
            snapshot_json LONGTEXT NOT NULL,source_manifest LONGTEXT NOT NULL,source_sha256 CHAR(64) NOT NULL,
            xml_content LONGTEXT NOT NULL,xml_sha256 CHAR(64) NOT NULL
        ) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TEMPORARY TABLE income_tax_returns (
            id INT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,final_snapshot_id BIGINT UNSIGNED NULL,
            computed LONGTEXT NULL
        ) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TEMPORARY TABLE tax_submissions (
            id INT UNSIGNED PRIMARY KEY,supplier_id INT UNSIGNED NOT NULL,form_code VARCHAR(20) NOT NULL,
            period_year SMALLINT UNSIGNED NOT NULL,xml_content LONGTEXT NOT NULL,xml_size_bytes INT UNSIGNED NOT NULL,
            xml_sha256 CHAR(64) NOT NULL, KEY idx_tax_submission_archive_xml (supplier_id,form_code,period_year,xml_sha256,id)
        ) ENGINE=InnoDB');
        $rows = [];
        for ($index = 0; $index < 1001; $index++) {
            $rows[] = ['id' => $index + 1, 'amount_cents' => 50010, 'note' => str_repeat('Žluťoučký ', 20)];
        }
        $this->sourceSnapshot = json_encode(['computed' => ['tax' => 500], 'rows' => $rows, 'entity' => 'Source Identity'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->targetSnapshot = json_encode(['computed' => ['tax' => 500], 'rows' => $rows, 'entity' => 'Anon Identity'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->sourceManifest = json_encode(['proof' => 'source', 'amount_cents' => 50010], JSON_THROW_ON_ERROR);
        $this->targetManifest = json_encode(['proof' => 'target', 'amount_cents' => 50010], JSON_THROW_ON_ERROR);
        $this->sourceComputed = json_encode(['tax' => 500, 'amount_cents' => 50010], JSON_THROW_ON_ERROR);
        $this->targetComputed = json_encode(['tax' => 500, 'amount_cents' => 50010], JSON_THROW_ON_ERROR);
        $this->sourceXml = '<Pisemnost><SourceIdentity/></Pisemnost>';
        $this->targetXml = '<Pisemnost><AnonIdentity/></Pisemnost>';
        $this->pdo->prepare('INSERT INTO income_tax_return_snapshots VALUES (11,21,7,?,?,?,?,?)')->execute([
            $this->sourceSnapshot,$this->sourceManifest,hash('sha256',$this->sourceManifest),$this->sourceXml,hash('sha256',$this->sourceXml),
        ]);
        $this->pdo->prepare('INSERT INTO income_tax_returns VALUES (21,7,11,?)')->execute([$this->sourceComputed]);
        $insert = $this->pdo->prepare("INSERT INTO tax_submissions VALUES (?,7,'dppdp9',2026,?,?,?)");
        foreach ([31,32] as $id) {
            $insert->execute([$id,$this->sourceXml,strlen($this->sourceXml),hash('sha256',$this->sourceXml)]);
        }
        $this->hashes = FinancialCloneHashMap::create($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->hashes)) {
            $this->hashes->drop();
        }
        if (isset($this->pdo)) {
            foreach (['income_tax_return_snapshots', 'income_tax_returns', 'tax_submissions'] as $table) {
                $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS ' . $table);
            }
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testExactGraphIsWrittenAtomicallyAndRollbackRestoresEveryRow(): void
    {
        $archive = $this->archive();
        $artifact = $this->artifact();
        $this->pdo->beginTransaction();
        (new FinancialArchiveTargetWriter($this->db))->apply($archive,$artifact,$this->submissions(),$this->hashes);

        $snapshot = $this->pdo->query('SELECT * FROM income_tax_return_snapshots WHERE id=11')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($this->targetSnapshot, $snapshot['snapshot_json']);
        self::assertSame($this->targetManifest, $snapshot['source_manifest']);
        self::assertSame(hash('sha256',$this->targetManifest), $snapshot['source_sha256']);
        self::assertSame($this->targetXml, $snapshot['xml_content']);
        self::assertSame(hash('sha256',$this->targetXml), $snapshot['xml_sha256']);
        self::assertSame($this->targetComputed, $this->pdo->query('SELECT computed FROM income_tax_returns WHERE id=21')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM tax_submissions WHERE xml_sha256='" . hash('sha256',$this->targetXml) . "'")->fetchColumn());
        self::assertSame(hash('sha256',$this->targetXml), $this->hashes->resolve(hash('sha256',$this->sourceXml)));

        $this->pdo->rollBack();
        self::assertSame($this->sourceSnapshot, $this->pdo->query('SELECT snapshot_json FROM income_tax_return_snapshots WHERE id=11')->fetchColumn());
        self::assertSame($this->sourceComputed, $this->pdo->query('SELECT computed FROM income_tax_returns WHERE id=21')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM tax_submissions WHERE xml_sha256='" . hash('sha256',$this->sourceXml) . "'")->fetchColumn());
    }

    public function testMissingDuplicateOrForeignSubmissionIsRejectedBeforeWrite(): void
    {
        foreach ([
            [[['tax_submission_id'=>31,'supplier_id'=>7,'source_xml_sha256'=>hash('sha256',$this->sourceXml)]], 'úplnou zachycenou množinu'],
            [[['tax_submission_id'=>31,'supplier_id'=>8,'source_xml_sha256'=>hash('sha256',$this->sourceXml)],$this->submissions()[1]], 'cizího tenanta'],
        ] as [$submissions,$message]) {
            $this->pdo->beginTransaction();
            try {
                (new FinancialArchiveTargetWriter($this->db))->apply($this->archive(),$this->artifact(),$submissions,$this->hashes);
                self::fail('Neúplná nebo cizí capture množina byla přijata.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString($message,$exception->getMessage());
            } finally {
                $this->pdo->rollBack();
            }
            self::assertSame($this->sourceSnapshot,$this->pdo->query('SELECT snapshot_json FROM income_tax_return_snapshots WHERE id=11')->fetchColumn());
        }
    }

    public function testStaleSnapshotOrReturnCasIsRejectedAndRollsBack(): void
    {
        $this->pdo->exec("UPDATE income_tax_return_snapshots SET snapshot_json='{}' WHERE id=11");
        $this->pdo->beginTransaction();
        try {
            (new FinancialArchiveTargetWriter($this->db))->apply($this->archive(),$this->artifact(),$this->submissions(),$this->hashes);
            self::fail('Stale archiv byl přepsán.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('capture důkazu',$exception->getMessage());
        } finally {
            $this->pdo->rollBack();
        }

        $this->pdo->prepare('UPDATE income_tax_return_snapshots SET snapshot_json=? WHERE id=11')->execute([$this->sourceSnapshot]);
        $this->pdo->exec("UPDATE income_tax_returns SET computed='{}' WHERE id=21");
        $this->pdo->beginTransaction();
        try {
            (new FinancialArchiveTargetWriter($this->db))->apply($this->archive(),$this->artifact(),$this->submissions(),$this->hashes);
            self::fail('Stale finální přiznání bylo přepsáno.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('finální přiznání',$exception->getMessage());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testTwoArchivesMayShareTheSameCapturedSubmissionSet(): void
    {
        $this->pdo->prepare('INSERT INTO income_tax_return_snapshots VALUES (12,21,7,?,?,?,?,?)')->execute([
            $this->sourceSnapshot, $this->sourceManifest, hash('sha256', $this->sourceManifest),
            $this->sourceXml, hash('sha256', $this->sourceXml),
        ]);
        $historic = new CapturedFinancialArchive(
            archiveId: 12, supplierId: 7, taxReturnId: 21, periodYear: 2026, submissionCount: 2,
            snapshotSha256: hash('sha256', $this->sourceSnapshot), sourceSha256: hash('sha256', $this->sourceManifest),
            xmlSha256: hash('sha256', $this->sourceXml), finalSnapshot: false, computedSha256: null,
        );
        $historicArtifact = new FinancialArchiveTargetArtifact(
            snapshot: FinancialArchiveSpool::fromString($this->targetSnapshot),
            sourceManifest: $this->targetManifest,
            computed: null,
            xml: FinancialArchiveSpool::fromString($this->targetXml),
        );
        $writer = new FinancialArchiveTargetWriter($this->db);
        $this->pdo->beginTransaction();
        $writer->apply($this->archive(), $this->artifact(), $this->submissions(), $this->hashes);
        $writer->apply($historic, $historicArtifact, $this->submissions(), $this->hashes);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM tax_submissions WHERE xml_sha256='" . hash('sha256', $this->targetXml) . "'")->fetchColumn());
        self::assertSame(hash('sha256', $this->targetXml), $this->pdo->query('SELECT xml_sha256 FROM income_tax_return_snapshots WHERE id=12')->fetchColumn());
        $this->pdo->rollBack();
    }

    public function testHistoricalRevisionDoesNotRewriteFinalReturn(): void
    {
        $historicXml = '<Pisemnost historic="1"/>';
        $targetXml = '<Pisemnost historic="anon"/>';
        $this->pdo->prepare('INSERT INTO income_tax_return_snapshots VALUES (12,21,7,?,?,?,?,?)')->execute([
            $this->sourceSnapshot, $this->sourceManifest, hash('sha256', $this->sourceManifest),
            $historicXml, hash('sha256', $historicXml),
        ]);
        $this->pdo->prepare("INSERT INTO tax_submissions VALUES (33,7,'dppdp9',2026,?,?,?)")
            ->execute([$historicXml, strlen($historicXml), hash('sha256', $historicXml)]);
        $archive = new CapturedFinancialArchive(
            archiveId: 12, supplierId: 7, taxReturnId: 21, periodYear: 2026, submissionCount: 1,
            snapshotSha256: hash('sha256', $this->sourceSnapshot), sourceSha256: hash('sha256', $this->sourceManifest),
            xmlSha256: hash('sha256', $historicXml), finalSnapshot: false, computedSha256: null,
        );
        $artifact = new FinancialArchiveTargetArtifact(
            snapshot: FinancialArchiveSpool::fromString($this->targetSnapshot),
            sourceManifest: $this->targetManifest,
            computed: null,
            xml: FinancialArchiveSpool::fromString($targetXml),
        );
        $this->pdo->beginTransaction();
        (new FinancialArchiveTargetWriter($this->db))->apply($archive, $artifact, [[
            'tax_submission_id' => 33,
            'supplier_id' => 7,
            'source_xml_sha256' => hash('sha256', $historicXml),
        ]], $this->hashes);
        self::assertSame($this->sourceComputed, $this->pdo->query('SELECT computed FROM income_tax_returns WHERE id=21')->fetchColumn());
        self::assertSame(hash('sha256', $targetXml), $this->pdo->query('SELECT xml_sha256 FROM income_tax_return_snapshots WHERE id=12')->fetchColumn());
        $this->pdo->rollBack();
    }

    private function archive(): CapturedFinancialArchive
    {
        return new CapturedFinancialArchive(
            archiveId:11,supplierId:7,taxReturnId:21,periodYear:2026,submissionCount:2,
            snapshotSha256:hash('sha256',$this->sourceSnapshot),sourceSha256:hash('sha256',$this->sourceManifest),
            xmlSha256:hash('sha256',$this->sourceXml),finalSnapshot:true,
            computedSha256:hash('sha256',$this->sourceComputed),
        );
    }

    private function artifact(): FinancialArchiveTargetArtifact
    {
        return new FinancialArchiveTargetArtifact(
            snapshot:FinancialArchiveSpool::fromString($this->targetSnapshot),
            sourceManifest:$this->targetManifest,
            computed:$this->targetComputed,
            xml:FinancialArchiveSpool::fromString($this->targetXml),
        );
    }

    /** @return list<array{tax_submission_id:int,supplier_id:int,source_xml_sha256:string}> */
    private function submissions(): array
    {
        return array_map(fn (int $id): array => [
            'tax_submission_id'=>$id,'supplier_id'=>7,'source_xml_sha256'=>hash('sha256',$this->sourceXml),
        ],[31,32]);
    }
}
