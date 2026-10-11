<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EpoDirectSubmissionRepository;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Epo\EpoAssistedConfirmationService;
use MyInvoice\Service\Epo\EpoConfirmationPartsArchiver;
use MyInvoice\Service\Epo\EpoDirectClient;
use MyInvoice\Service\Epo\EpoDirectResponseParser;
use MyInvoice\Service\Epo\EpoDirectSubmissionService;
use MyInvoice\Service\Epo\EpoPkcs7Signer;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Epo\EpoSubmissionPayloadBuilder;
use MyInvoice\Service\Epo\TaxSubmissionDocumentService;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class EpoDirectSubmissionRepositoryTest extends TestCase
{
    private Connection $db;
    private EpoDirectSubmissionRepository $direct;
    private EpoSigningCredentialRepository $credentials;
    private TaxSubmissionEpoRepository $epo;
    private TaxSubmissionRepository $submissions;
    private ContainerInterface $container;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->container = $container;
            $this->db = $container->get(Connection::class);
            $this->direct = $container->get(EpoDirectSubmissionRepository::class);
            $this->credentials = $container->get(EpoSigningCredentialRepository::class);
            $this->epo = $container->get(TaxSubmissionEpoRepository::class);
            $this->submissions = $container->get(TaxSubmissionRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($this->supplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí základní data.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testSuccessfulTestCanBeClaimedOnlyOnce(): void
    {
        $pdo = $this->db->pdo();
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $sha256 = hash('sha256', $xml);
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2026,
            7,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $fingerprint = hash('sha256', 'synthetic-direct-credential-' . random_bytes(8));
        $credentialId = $this->credentials->create($this->userId, [
            'label' => 'Syntetický přímý EPO certifikát',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => $fingerprint,
            'subject_dn' => 'CN=Synthetic Direct EPO Signer',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '02',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'ik_mpsv_present' => false,
        ]);
        $attemptId = $this->direct->createAttempt(
            $this->supplierId,
            $submissionId,
            $credentialId,
            $fingerprint,
            $sha256,
            $this->userId,
            'production',
        );
        $this->direct->recordTest($attemptId, true, [], 200);
        $this->direct->storeEncryptedTestPayload($attemptId, 'encrypted-test');
        $this->direct->storeEncryptedSubmittedPayload($attemptId, 'encrypted-submit');
        $this->direct->storeEncryptedResponse($attemptId, 'encrypted-response', 200);
        $this->direct->storeEncryptedConfirmationPayload(
            $attemptId,
            'encrypted-confirmation',
            200,
        );
        $secondAttemptId = $this->direct->createAttempt(
            $this->supplierId,
            $submissionId,
            $credentialId,
            $fingerprint,
            $sha256,
            $this->userId,
            'production',
        );
        $this->direct->recordTest($secondAttemptId, true, [], 200);

        $pdo->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, status, idempotency_key,
                 request_sha256, requested_by)
             VALUES (?, ?, 'epo_assisted', 'prepared', ?, ?, ?)"
        )->execute([
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            $sha256,
            $this->userId,
        ]);
        $assistedAttemptId = (int) $pdo->lastInsertId();
        self::assertTrue($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'production',
        ));
        self::assertFalse($this->direct->claimTestPassedAttempt(
            $attemptId,
            $submissionId,
            $this->supplierId,
            $this->userId,
            $sha256,
            'production',
        ));
        $pdo->prepare(
            "UPDATE tax_submission_attempts SET status = 'cancelled' WHERE id = ?"
        )->execute([$assistedAttemptId]);

        self::assertTrue($this->direct->claimTestPassedAttempt(
            $attemptId,
            $submissionId,
            $this->supplierId,
            $this->userId,
            $sha256,
            'production',
        ));
        self::assertTrue($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'production',
        ));
        self::assertSame(
            'epo_direct',
            $this->epo->activeAttempt($submissionId, $this->supplierId)['channel'],
        );
        self::assertFalse($this->direct->claimTestPassedAttempt(
            $secondAttemptId,
            $submissionId,
            $this->supplierId,
            $this->userId,
            $sha256,
            'production',
        ));
        self::assertFalse($this->direct->claimTestPassedAttempt(
            $attemptId,
            $submissionId,
            $this->supplierId,
            $this->userId,
            $sha256,
            'production',
        ));
        $attempt = $this->direct->findAttempt($attemptId, $submissionId, $this->supplierId);
        self::assertSame('submitting', $attempt['status']);
        self::assertSame('encrypted-test', $attempt['test_signed_ciphertext']);
        self::assertSame('encrypted-submit', $attempt['submitted_signed_ciphertext']);
        self::assertSame('encrypted-response', $attempt['last_response_ciphertext']);
        self::assertSame('encrypted-confirmation', $attempt['confirmation_ciphertext']);
    }

    /**
     * Pokus, u kterého se potvrzenku nepodařilo ověřit, musí jít dotáhnout přes „Obnovit stav".
     *
     * Potvrzenka se ukládá DŘÍV, než se zapíše podací číslo a heslo, takže takový pokus
     * nikdy nemá `remote_submission_ref` — a podmínka, která na ně spoléhala, tlačítko
     * schovala. Uživateli pak zbývalo jen ruční nahrání souboru, který nemá odkud vzít:
     * leží zašifrovaný v databázi. V ostrém provozu to znamenalo přijaté kontrolní hlášení,
     * které aplikace uměla doložit, ale ne uzavřít.
     */
    public function testUnverifiableConfirmationStillOffersRefresh(): void
    {
        $xml = '<Pisemnost><DPHKH1/></Pisemnost>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphkh1',
            2026,
            7,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $fingerprint = hash('sha256', 'refresh-flag-' . random_bytes(8));
        $credentialId = $this->credentials->create($this->userId, [
            'label' => 'Syntetický certifikát pro obnovu stavu',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => $fingerprint,
            'subject_dn' => 'CN=Synthetic Refresh Signer',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '04',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'ik_mpsv_present' => false,
        ]);
        $attemptId = $this->direct->createAttempt(
            $this->supplierId,
            $submissionId,
            $credentialId,
            $fingerprint,
            hash('sha256', $xml),
            $this->userId,
            'production',
        );

        // Přesně stav z produkce: odeslaný balíček i potvrzenka uložené, podací číslo ne.
        $this->direct->storeEncryptedSubmittedPayload($attemptId, 'encrypted-submit');
        $this->direct->storeEncryptedConfirmationPayload($attemptId, 'encrypted-confirmation', 200);
        $this->direct->setStatus(
            $attemptId,
            'uncertain',
            200,
            'invalid_confirmation',
            'EPO vrátilo potvrzení, které se nepodařilo bezpečně ověřit.',
        );

        $attempts = array_column($this->epo->attempts($submissionId, $this->supplierId), null, 'id');
        self::assertArrayHasKey($attemptId, $attempts);
        $attempt = $attempts[$attemptId];

        self::assertNull($attempt['remote_submission_ref'], 'Předpoklad testu: podací číslo chybí.');
        self::assertTrue(
            (bool) $attempt['refresh_available'],
            'Uložená potvrzenka + odeslaný balíček stačí na znovuověření — tlačítko musí být k dispozici.'
        );
    }

    public function testProductionAttemptCannotBeSubmittedWhileClientIsInSandbox(): void
    {
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $sha256 = hash('sha256', $xml);
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2026,
            7,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $fingerprint = hash('sha256', 'synthetic-environment-guard-' . random_bytes(8));
        $credentialId = $this->credentials->create($this->userId, [
            'label' => 'Syntetický certifikát pro guard prostředí',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => $fingerprint,
            'subject_dn' => 'CN=Synthetic Environment Guard',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '03',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'ik_mpsv_present' => false,
        ]);
        $attemptId = $this->direct->createAttempt(
            $this->supplierId,
            $submissionId,
            $credentialId,
            $fingerprint,
            $sha256,
            $this->userId,
            'production',
        );
        $this->direct->recordTest($attemptId, true, [], 200);

        $service = new EpoDirectSubmissionService(
            $this->db,
            $this->submissions,
            $this->container->get(TaxSubmissionArchiver::class),
            $this->epo,
            $this->direct,
            $this->container->get(EpoSigningCredentialService::class),
            $this->container->get(EpoPkcs7Signer::class),
            $this->container->get(EpoSubmissionPayloadBuilder::class),
            new EpoDirectClient(null, 'test'),
            $this->container->get(EpoDirectResponseParser::class),
            $this->container->get(TaxSubmissionDocumentService::class),
            $this->container->get(SecretEncryption::class),
            $this->container->get(EpoConfirmationPartsArchiver::class),
            $this->container->get(EpoAssistedConfirmationService::class),
        );

        try {
            $service->submit($submissionId, $this->supplierId, $this->userId, $attemptId);
            self::fail('Produkční pokus nesmí být odeslán klientem nastaveným na sandbox.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('epo_environment_changed', $e->errorCode);
        }
        self::assertSame(
            'test_passed',
            $this->direct->findAttempt($attemptId, $submissionId, $this->supplierId)['status'] ?? null,
        );
    }

    public function testUncertainAttemptCanOnlyBeReleasedWithoutRemoteIdentifiers(): void
    {
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2026,
            8,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, status, idempotency_key,
                 request_sha256, requested_by)
             VALUES (?, ?, 'epo_direct', ?, ?, ?, ?)"
        );
        $insert->execute([
            $this->supplierId,
            $submissionId,
            'uncertain',
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
        ]);
        $attemptId = (int) $pdo->lastInsertId();

        self::assertTrue($this->direct->resolveAsNotSubmitted(
            $attemptId,
            $submissionId,
            $this->supplierId,
            $this->userId,
            'Ověřeno v portálu EPO podle času a subjektu.',
        ));
        $resolved = $this->direct->findAttempt($attemptId, $submissionId, $this->supplierId);
        self::assertSame('cancelled', $resolved['status']);
        self::assertSame('verified_not_submitted', $resolved['resolution_code']);
        self::assertSame($this->userId, (int) $resolved['resolved_by']);
        self::assertNotEmpty($resolved['resolved_at']);
        self::assertFalse($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'production',
        ));

        $insert->execute([
            $this->supplierId,
            $submissionId,
            'uncertain',
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
        ]);
        $withReferenceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "UPDATE tax_submission_attempts
                SET remote_submission_ref = 'EPO-REMOTE'
              WHERE id = ?"
        )->execute([$withReferenceId]);
        self::assertFalse($this->direct->resolveAsNotSubmitted(
            $withReferenceId,
            $submissionId,
            $this->supplierId,
            $this->userId,
            'Tento pokus se nesmí uvolnit.',
        ));
    }

    public function testSandboxAttemptIsPersistedAndDoesNotBlockProductionAfterConfirmation(): void
    {
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2026,
            11,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, epo_environment,
                 status, idempotency_key, request_sha256, requested_by)
             VALUES (?, ?, 'epo_direct', 'test', 'confirmed', ?, ?, ?)"
        )->execute([
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
        ]);
        $attemptId = (int) $pdo->lastInsertId();
        $this->direct->addEvent(
            $this->supplierId,
            $submissionId,
            $attemptId,
            'submission_confirmed',
            'confirmed',
            200,
            [],
            $this->userId,
        );

        self::assertFalse($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'production',
        ));
        self::assertFalse($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'test',
        ));
        self::assertSame('test', $this->epo->attempts(
            $submissionId,
            $this->supplierId,
        )[0]['epo_environment']);
        self::assertSame(
            'test',
            $pdo->query(
                'SELECT epo_environment FROM tax_submission_status_events WHERE attempt_id = '
                . $attemptId . ' ORDER BY id DESC LIMIT 1'
            )->fetchColumn(),
        );
        self::assertSame(
            'downloaded',
            $this->submissions->find($submissionId, $this->supplierId)['status'],
        );

        $pdo->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, epo_environment,
                 status, idempotency_key, request_sha256, requested_by)
             VALUES (?, ?, 'epo_direct', 'production', 'uncertain', ?, ?, ?)"
        )->execute([
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
        ]);
        self::assertTrue($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'production',
        ));
        self::assertTrue($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'test',
        ));
    }

    public function testAbandonedAssistedPreparationStopsBlockingAfterFiveMinutes(): void
    {
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2026,
            10,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, status, idempotency_key,
                 request_sha256, requested_by, requested_at)
             VALUES (?, ?, 'epo_assisted', 'prepared', ?, ?, ?,
                     CURRENT_TIMESTAMP - INTERVAL 6 MINUTE)"
        )->execute([
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
        ]);

        self::assertFalse($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'production',
        ));
    }

    public function testActiveAssistedHandoffBlocksSubmissionButNotDirectTest(): void
    {
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2026,
            10,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, status, idempotency_key,
                 request_sha256, requested_by, handoff_expires_at)
             VALUES (?, ?, 'epo_assisted', 'awaiting_confirmation', ?, ?, ?,
                     CURRENT_TIMESTAMP + INTERVAL 20 MINUTE)"
        )->execute([
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
        ]);

        self::assertTrue($this->direct->hasUnresolvedLiveAttempt(
            $submissionId,
            $this->supplierId,
            'production',
        ));
        self::assertFalse($this->direct->hasUnresolvedDirectAttempt(
            $submissionId,
            $this->supplierId,
            'production',
        ));

        $validatedSubmission = new \ReflectionMethod(
            EpoDirectSubmissionService::class,
            'validatedSubmission',
        );
        $validated = $validatedSubmission->invoke(
            $this->container->get(EpoDirectSubmissionService::class),
            $submissionId,
            $this->supplierId,
            false,
            'production',
            true,
        );
        self::assertSame($submissionId, (int) $validated['id']);
    }

    /**
     * Odkaz do EPO založený po úspěšném testu podání blokuje. Vědomé přebití ho
     * zruší (jen otevřené předání, uzavřené pokusy nechá být) a claim pak projde.
     */
    public function testOpenHandoffCanBeSupersededByDirectSubmission(): void
    {
        $pdo = $this->db->pdo();
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $sha256 = hash('sha256', $xml);
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2026,
            11,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $fingerprint = hash('sha256', 'synthetic-supersede-credential-' . random_bytes(8));
        $credentialId = $this->credentials->create($this->userId, [
            'label' => 'Syntetický přímý EPO certifikát',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => $fingerprint,
            'subject_dn' => 'CN=Synthetic Direct EPO Signer',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '03',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'ik_mpsv_present' => false,
        ]);
        $attemptId = $this->direct->createAttempt(
            $this->supplierId,
            $submissionId,
            $credentialId,
            $fingerprint,
            $sha256,
            $this->userId,
            'production',
        );
        $this->direct->recordTest($attemptId, true, [], 200);
        $pdo->prepare('UPDATE tax_submission_attempts SET requested_at = CURRENT_TIMESTAMP - INTERVAL 2 MINUTE WHERE id = ?')
            ->execute([$attemptId]);

        $insertAssisted = $pdo->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, status, idempotency_key,
                 request_sha256, requested_by, handoff_expires_at)
             VALUES (?, ?, 'epo_assisted', ?, ?, ?, ?, CURRENT_TIMESTAMP + INTERVAL 20 MINUTE)"
        );
        $insertAssisted->execute([$this->supplierId, $submissionId, 'handoff_created', bin2hex(random_bytes(16)), $sha256, $this->userId]);
        $openId = (int) $pdo->lastInsertId();
        $insertAssisted->execute([$this->supplierId, $submissionId, 'expired', bin2hex(random_bytes(16)), $sha256, $this->userId]);
        $expiredId = (int) $pdo->lastInsertId();

        self::assertFalse($this->direct->claimTestPassedAttempt(
            $attemptId,
            $submissionId,
            $this->supplierId,
            $this->userId,
            $sha256,
            'production',
        ));

        self::assertSame([$openId], $this->direct->cancelOpenAssistedAttempts(
            $submissionId,
            $this->supplierId,
            'production',
        ));
        self::assertTrue($this->direct->claimTestPassedAttempt(
            $attemptId,
            $submissionId,
            $this->supplierId,
            $this->userId,
            $sha256,
            'production',
        ));

        $status = $pdo->prepare('SELECT status, error_code FROM tax_submission_attempts WHERE id = ?');
        $status->execute([$openId]);
        self::assertSame(['status' => 'cancelled', 'error_code' => 'superseded_by_direct'], $status->fetch(\PDO::FETCH_ASSOC));
        $status->execute([$expiredId]);
        self::assertSame('expired', $status->fetch(\PDO::FETCH_ASSOC)['status']);
    }

    public function testPollQueueContainsOnlyDueAttemptsWithStatusCredentials(): void
    {
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2026,
            9,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, status, idempotency_key,
                 request_sha256, requested_by, remote_submission_ref,
                 state_password_ciphertext, next_poll_at)
             VALUES (?, ?, 'epo_direct', 'processing', ?, ?, ?, 'EPO-POLL',
                     'encrypted-password', CURRENT_TIMESTAMP - INTERVAL 1 MINUTE)"
        )->execute([
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
        ]);
        $attemptId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, status, idempotency_key,
                 request_sha256, requested_by, remote_submission_ref,
                 state_password_ciphertext, next_poll_at, epo_environment)
             VALUES (?, ?, 'epo_direct', 'processing', ?, ?, ?, 'EPO-TEST-POLL',
                     'encrypted-test-password', CURRENT_TIMESTAMP - INTERVAL 1 MINUTE, 'test')"
        )->execute([
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
        ]);
        $testAttemptId = (int) $pdo->lastInsertId();

        $queued = $this->direct->pollableAttempts(200, 'production');
        self::assertContains(
            $attemptId,
            array_map(static fn (array $row): int => $row['attempt_id'], $queued),
        );
        self::assertNotContains(
            $testAttemptId,
            array_map(static fn (array $row): int => $row['attempt_id'], $queued),
        );
        $testQueued = $this->direct->pollableAttempts(200, 'test');
        self::assertContains(
            $testAttemptId,
            array_map(static fn (array $row): int => $row['attempt_id'], $testQueued),
        );
        $testAttempt = $this->direct->findAttempt(
            $testAttemptId,
            $submissionId,
            $this->supplierId,
        );
        self::assertSame(0, (int) $testAttempt['poll_count']);
        $this->direct->scheduleNextPoll($attemptId, 120);
        $attempt = $this->direct->findAttempt($attemptId, $submissionId, $this->supplierId);
        self::assertSame(1, (int) $attempt['poll_count']);
        self::assertNotEmpty($attempt['next_poll_at']);
    }
}
