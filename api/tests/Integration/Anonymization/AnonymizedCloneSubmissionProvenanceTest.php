<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EpoDirectSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\DefaultSubmissionArtifactResolver;
use PHPUnit\Framework\TestCase;

final class AnonymizedCloneSubmissionProvenanceTest extends TestCase
{
    private Connection $db;
    private int $supplierId;
    private int $submissionId;
    private string $xml;
    private DefaultSubmissionArtifactResolver $resolver;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->resolver = $container->get(DefaultSubmissionArtifactResolver::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->supplierId = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->xml = '<Pisemnost synthetic="anonymized-clone"/>';
        $this->submissionId = (new TaxSubmissionRepository($this->db))->archive(
            $this->supplierId,
            'dphdp3',
            2099,
            1,
            null,
            $this->xml,
            [],
            'passed',
            [],
            null,
        );
        $this->db->pdo()->prepare(
            "INSERT INTO anonymized_clone_submission_provenance
                (supplier_id,tax_submission_id,source_xml_sha256,target_xml_sha256,transport_receipt_root,
                 transport_class,attempt_count,status_event_count,artifact_count,outbox_count)
             VALUES (?,?,?,?,?,'source_provenance',1,2,3,4)",
        )->execute([
            $this->supplierId,
            $this->submissionId,
            hash('sha256', $this->xml),
            hash('sha256', $this->xml . '-target'),
            hash('sha256', 'synthetic-transport-receipt'),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testMappedSourceReceiptCannotBeResolvedAsSendableTargetXml(): void
    {
        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('zdrojového transportu');

        $this->resolver->resolve($this->supplierId, 'tax_submission', $this->submissionId);
    }

    public function testMappedSourceReceiptCannotBeEnqueuedAgain(): void
    {
        $repository = new SubmissionOutboxRepository($this->db);
        self::assertTrue($repository->isAvailable());

        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('zdrojového transportu');
        $repository->enqueue([
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'agenda_code' => 'DPHDP3',
            'recipient_id' => null,
            'recipient_box_id' => 'abcdefg',
            'subject' => 'Syntetické podání',
            'artifact_kind' => 'tax_submission',
            'artifact_id' => $this->submissionId,
            'artifact_filename' => 'synthetic.xml',
            'artifact_sha256' => hash('sha256', $this->xml),
            'correlation_reference' => 'SYNTHETIC-CLONE-' . $this->submissionId,
            'created_by' => null,
        ], 'synthetic-clone-' . $this->submissionId);
    }

    public function testMappedSourceReceiptCannotCreateAssistedHandoff(): void
    {
        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('zdrojového transportu');

        (new TaxSubmissionEpoRepository($this->db))->insertAttempt(
            $this->supplierId,
            $this->submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $this->xml),
            null,
            'test',
        );
    }

    public function testMappedSourceReceiptCannotCreateDirectAttempt(): void
    {
        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('zdrojového transportu');

        (new EpoDirectSubmissionRepository($this->db))->createAttempt(
            $this->supplierId,
            $this->submissionId,
            1,
            hash('sha256', 'synthetic-fingerprint'),
            hash('sha256', $this->xml),
            null,
            'test',
        );
    }

    public function testNewUnmappedSubmissionKeepsNormalResolverAndQueueLifecycle(): void
    {
        $newXml = '<Pisemnost synthetic="post-clone"/>';
        $newId = (new TaxSubmissionRepository($this->db))->archive(
            $this->supplierId,
            'dphdp3',
            2100,
            1,
            null,
            $newXml,
            [],
            'passed',
            [],
            null,
        );

        self::assertSame($newXml, $this->resolver->resolve($this->supplierId, 'tax_submission', $newId)['bytes']);
        $queued = (new SubmissionOutboxRepository($this->db))->enqueue([
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'agenda_code' => 'DPHDP3',
            'recipient_id' => null,
            'recipient_box_id' => 'abcdefg',
            'subject' => 'Nové syntetické podání',
            'artifact_kind' => 'tax_submission',
            'artifact_id' => $newId,
            'artifact_filename' => 'new-synthetic.xml',
            'artifact_sha256' => hash('sha256', $newXml),
            'correlation_reference' => 'SYNTHETIC-NEW-' . $newId,
            'created_by' => null,
        ], 'synthetic-new-' . $newId);

        self::assertTrue($queued['created']);
        self::assertSame($newId, $queued['row']['artifact_id']);
    }
}
