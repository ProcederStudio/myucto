<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Action\Report\TaxSubmissionIsdsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Epo\EpoSubmissionService;
use MyInvoice\Service\Epo\Isds\TaxIsdsRouting;
use MyInvoice\Service\Epo\Isds\TaxSubmissionDispatchProjection;
use MyInvoice\Service\Epo\Isds\TaxSubmissionIsdsService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDispatchProjection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Submission\SubmissionChannelRegistry;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Daňové podání datovou schránkou: směrování na schránku FÚ, zařazení do fronty,
 * odeslání, promítnutí do `tax_submissions` a jeden kanál na jeden snapshot.
 */
#[Group('integration')]
final class TaxSubmissionIsdsTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private TaxSubmissionRepository $submissions;
    private TaxSubmissionEpoRepository $epo;
    private TaxSubmissionIsdsService $isds;
    private TaxIsdsRouting $routing;
    private FakeIsdsTransport $transport;
    private SubmissionOutboxService $sender;
    private int $supplierId;
    private int $userId;
    private int $year;
    private int $month;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }

        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->submissions = $this->container->get(TaxSubmissionRepository::class);
        $this->epo = $this->container->get(TaxSubmissionEpoRepository::class);
        $this->isds = $this->container->get(TaxSubmissionIsdsService::class);
        $this->routing = $this->container->get(TaxIsdsRouting::class);

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0) {
            $this->markTestSkipped('V DB chybí uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createSupplier('461');

        $previous = new \DateTimeImmutable('first day of last month');
        $this->year = (int) $previous->format('Y');
        $this->month = (int) $previous->format('n');

        $this->transport = new FakeIsdsTransport();
        $this->sender = new SubmissionOutboxService(
            new SubmissionOutboxRepository($this->db),
            new SubmissionOutboxAttemptRepository($this->db),
            new SubmissionRecipientRepository($this->db),
            new SubmissionChannelRegistry(
                $this->container->get(EpoChannel::class),
                new IsdsChannel($this->transport),
            ),
            $this->container->get(SubmissionArtifactResolver::class),
            $this->container->get(SubmissionArtifactValidator::class),
            new NullLogger(),
            $this->container->get(PayrollSubmissionDispatchProjection::class),
            $this->container->get(TaxSubmissionDispatchProjection::class),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testVatReturnGoesToTaxOfficeOfXmlAndSendingMarksItSubmitted(): void
    {
        $submissionId = $this->vatReturn();

        $queued = $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);

        self::assertSame('fu_461', $queued['recipient']['code']);
        self::assertSame('qdhny4c', $queued['recipient']['box_id']);
        self::assertSame('office_code', $queued['recipient']['source']);
        self::assertSame('461', $queued['xml_office_code']);
        self::assertFalse($queued['office_mismatch']);
        self::assertSame(
            sprintf('Přiznání k DPH za %02d/%d, řádné, DIČ CZ12345678', $this->month, $this->year),
            $queued['subject'],
        );
        self::assertSame('DPHDP3', $queued['row']['agenda_code']);
        self::assertSame(
            TaxSubmissionEpoRepository::BLOCK_ISDS_PENDING,
            $this->epo->deletionBlocker($submissionId, $this->supplierId),
        );

        $again = $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);
        self::assertFalse($again['created'], 'Opakované zařazení vrací tutéž zprávu.');
        self::assertSame($queued['outbox_id'], $again['outbox_id']);

        $sent = $this->sender->confirmAndSend(
            $this->supplierId,
            $queued['outbox_id'],
            $this->userId,
            $this->context(),
        );

        self::assertTrue($sent['dispatched'], (string) ($sent['row']['last_error_message'] ?? ''));
        self::assertCount(1, $this->transport->sentMessages);
        self::assertSame('qdhny4c', $this->transport->sentMessages[0]['recipient']);
        $submission = $this->submissions->find($submissionId, $this->supplierId);
        self::assertNotNull($submission);
        self::assertSame('submitted', $submission['status']);
        self::assertSame('ISDS DM-1000', $submission['submission_ref']);
        self::assertNotNull($submission['submitted_at']);
        self::assertSame(
            TaxSubmissionEpoRepository::BLOCK_SUBMITTED,
            $this->epo->deletionBlocker($submissionId, $this->supplierId),
        );
        $enriched = $this->epo->enrich([$submission], $this->supplierId)[0];
        self::assertSame('sent', $enriched['isds_dispatch']['dispatch_state']);
        self::assertSame('DM-1000', $enriched['isds_dispatch']['external_message_id']);
    }

    public function testQueuedSnapshotBlocksEpoHandoff(): void
    {
        $submissionId = $this->vatReturn();
        self::assertNull($this->epo->activeIsdsDispatch($submissionId, $this->supplierId));
        $queued = $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);

        // Na tuhle podmínku se ptá předání do EPO i přímé EPO podání; samotné
        // createHandoff() si otevírá vlastní transakci, kterou test v transakci nespustí.
        $active = $this->epo->activeIsdsDispatch($submissionId, $this->supplierId);
        self::assertNotNull($active);
        self::assertSame($queued['outbox_id'], (int) $active['id']);

        $this->sender->cancel($this->supplierId, $queued['outbox_id']);
        self::assertNull(
            $this->epo->activeIsdsDispatch($submissionId, $this->supplierId),
            'Zrušená zpráva snapshot pro EPO uvolní.',
        );
        self::assertNull($this->epo->deletionBlocker($submissionId, $this->supplierId));
    }

    public function testActiveEpoHandoffBlocksQueueing(): void
    {
        $submissionId = $this->vatReturn();
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, epo_environment, status,
                 idempotency_key, request_sha256, requested_by, handoff_expires_at)
             VALUES (?, ?, 'epo_assisted', 'production', 'handoff_created', ?, ?, ?, NOW() + INTERVAL 1 HOUR)"
        )->execute([
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', 'handoff'),
            $this->userId,
        ]);

        try {
            $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);
            self::fail('Zařazení muselo být odmítnuto.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('epo_attempt_active', $e->errorCode);
        }
    }

    /** Mezi zařazením a odesláním někdo snapshot podal jinou cestou — fronta ho nepustí. */
    public function testSnapshotFiledMeanwhileIsNotSentAgain(): void
    {
        $submissionId = $this->vatReturn();
        $queued = $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);
        $this->container->get(TaxSubmissionArchiver::class)->markSubmitted(
            $submissionId,
            $this->supplierId,
            date('Y-m-d H:i:s'),
            'EPO 123',
            $this->userId,
        );

        $sent = $this->sender->confirmAndSend(
            $this->supplierId,
            $queued['outbox_id'],
            $this->userId,
            $this->context(),
        );

        self::assertFalse($sent['dispatched']);
        self::assertSame('failed', $sent['row']['dispatch_state']);
        self::assertSame('tax_submission_already_filed', $sent['row']['last_error_code']);
        self::assertSame([], $this->transport->sentMessages);
    }

    public function testRouteOverrideIsUsedReportedAndRequeueCancelsOldTarget(): void
    {
        $submissionId = $this->vatReturn();
        $sfu = (new SubmissionRecipientRepository($this->db))->findVisibleByCode($this->supplierId, 'fu_13');
        self::assertNotNull($sfu);

        $first = $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);
        $this->routing->saveRoutes($this->supplierId, ['vat' => (int) $sfu['id']], $this->userId);
        $routes = $this->routing->routes($this->supplierId);
        self::assertSame('fu_461', $routes['groups']['vat']['default']['code']);
        self::assertSame('fu_13', $routes['groups']['vat']['effective']['code']);
        self::assertSame('fu_461', $routes['groups']['kh']['effective']['code']);

        $second = $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);

        self::assertSame('7cs8cge', $second['recipient']['box_id']);
        self::assertSame('override', $second['recipient']['source']);
        self::assertTrue($second['office_mismatch'], 'XML míří na FÚ 461, schránka na SFÚ.');
        $old = (new SubmissionOutboxRepository($this->db))->find($this->supplierId, $first['outbox_id']);
        self::assertSame('cancelled', $old['dispatch_state']);

        $this->routing->saveRoutes($this->supplierId, ['vat' => null], $this->userId);
        self::assertNull($this->routing->routes($this->supplierId)['groups']['vat']['override']);
    }

    /** Zrušení ze stránky podání uvolní snapshot; odeslanou zprávu zrušit nejde. */
    public function testCancelEndpointReleasesOnlyQueuedMessage(): void
    {
        $action = $this->container->get(TaxSubmissionIsdsAction::class);
        $submissionId = $this->vatReturn();
        $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);

        $response = $action->cancel($this->request($submissionId), new Psr7Response(), ['id' => (string) $submissionId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->epo->activeIsdsDispatch($submissionId, $this->supplierId));

        $queued = $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);
        self::assertTrue($queued['created'], 'Po zrušení jde snapshot zařadit znovu.');
        $this->sender->confirmAndSend($this->supplierId, $queued['outbox_id'], $this->userId, $this->context());

        $refused = $action->cancel($this->request($submissionId), new Psr7Response(), ['id' => (string) $submissionId]);
        self::assertSame(409, $refused->getStatusCode());
    }

    public function testUnsupportedFormIsRefused(): void
    {
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'ossei1',
            $this->year,
            null,
            1,
            '<?xml version="1.0"?><Pisemnost/>',
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );

        $this->expectException(SubmissionChannelException::class);
        $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);
    }

    /** Doručenka i zpráva FÚ se přiloží k podání jako důkazní artefakty. */
    public function testDeliveryReceiptIsArchivedWithTheSubmission(): void
    {
        $submissionId = $this->vatReturn();
        $queued = $this->isds->enqueue($this->supplierId, $submissionId, $this->userId);
        $this->sender->confirmAndSend($this->supplierId, $queued['outbox_id'], $this->userId, $this->context());
        $documentId = $this->document('dorucenka');

        $this->sender->attachReceipt($this->supplierId, $queued['outbox_id'], $documentId, null, 'manual');

        $kinds = array_column($this->epo->artifacts($submissionId, $this->supplierId), 'artifact_kind');
        self::assertContains('isds_delivery_zfo', $kinds);
    }

    public function testOfficeCodeIsReadFromXml(): void
    {
        self::assertSame('13', TaxIsdsRouting::officeCodeFromXml(
            '<Pisemnost><DPPDP9><VetaD c_ufo_cil="13"/><VetaP c_ufo="451"/></DPPDP9></Pisemnost>',
        ));
        self::assertSame('452', TaxIsdsRouting::officeCodeFromXml('<Pisemnost><X><VetaP c_ufo="452"/></X></Pisemnost>'));
        self::assertNull(TaxIsdsRouting::officeCodeFromXml('<!DOCTYPE x><x/>'));
        self::assertSame('fu_13', TaxIsdsRouting::recipientCodeFor('13'));
        self::assertNull(TaxIsdsRouting::recipientCodeFor('2001'));
    }

    private function vatReturn(): int
    {
        $built = $this->container->get(DphPriznaniBuilder::class)
            ->build($this->supplierId, $this->year, $this->month, 'monthly');
        $archived = $this->container->get(TaxSubmissionArchiver::class)->archive(
            $this->supplierId,
            'dphdp3',
            $this->year,
            $this->month,
            null,
            (string) $built['xml'],
            [],
            $this->userId,
            false,
        );
        self::assertSame('passed', $archived['validation_status'], implode("\n", $archived['validation_errors']));

        return (int) $archived['submission_id'];
    }

    private function request(int $submissionId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/reports/submissions/' . $submissionId . '/isds-cancel')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    private function context(): ChannelContext
    {
        return new ChannelContext($this->supplierId, 'production', new ChannelCredentials('zzzzzzz', 'certificate'));
    }

    private function document(string $seed): int
    {
        $sha = hash('sha256', $seed . '-' . $this->supplierId . '-' . random_int(1, PHP_INT_MAX));
        return $this->container->get(DocumentRepository::class)->insert([
            'supplier_id' => $this->supplierId,
            'folder_id' => null,
            'title' => $seed . '.zfo',
            'description' => null,
            'original_name' => $seed . '.zfo',
            'filename' => substr($sha, 0, 8) . '-' . $seed . '.zfo',
            'sha256' => $sha,
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 20,
            'doc_type' => 'other',
            'uploaded_by' => $this->userId,
            'scope' => 'company',
            'owner_user_id' => null,
        ]);
    }

    private function createSupplier(string $officeCode): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' ORDER BY is_default DESC, id LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní CZ číselníky.');
        }
        $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, ic, dic, is_vat_payer, email, phone,
                 default_currency_id, default_vat_rate_id, taxpayer_type, financial_office_code,
                 workplace_code, street_number_pop, street_number_orient)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'TEST ISDS s.r.o.', 'Testovací', 'Brno', '60200', $countryId,
            '00000019', 'CZ12345678', 'isds-test@example.invalid', '123456789',
            $currencyId, $vatRateId, 'po', $officeCode, '3001', '42', '7',
        ]);
        return (int) $pdo->lastInsertId();
    }
}
