<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\{Connection, NamedLockName};
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Mail\{Mailer, PaymentThanksMailer, PaymentThanksOutbox};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\{Envelope, SentMessage};
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class BankPaymentThanksCommitBoundaryTest extends TestCase
{
    private Connection $db;
    private Connection $observer;
    private StatementMatcher $matcher;
    private PaymentThanksMailer $thanks;
    private Mailer $mailer;
    private CommitBoundaryTransport $transport;
    private int $supplier = 0;
    private int $invoice = 0;
    private int $transaction = 0;
    private int $statement = 0;
    private int $client = 0;
    private int $currency = 0;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $this->observer = Connection::withoutSharedTestConnection(static function (): Connection {
            $db = Bootstrap::buildContainer()->get(Connection::class); $db->pdo(); return $db;
        });
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode,payment_thanks_enabled,payment_thanks_auto_send,payment_thanks_attach_paid_pdf)
            SELECT 'Synthetic commit boundary','Test 1','Test','10000','sender@example.test',country_id,default_currency_id,default_vat_rate_id,'tax_evidence',1,1,0 FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        self::assertGreaterThan(0, $this->supplier);
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES (?,'CZK','Synthetic boundary','Kč','koruna','crown',2,1,1,'19-1000000005','0100')")->execute([$this->supplier]);
        $this->currency = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT ?,'Synthetic recipient','Test 1','Test','10000',country_id,'recipient@example.test',? FROM supplier WHERE id=?")->execute([$this->supplier, $this->currency, $this->supplier]);
        $this->client = (int) $pdo->lastInsertId();
        $vs = (string) (800000000 + $this->supplier);
        $pdo->prepare("INSERT INTO invoices(invoice_type,varsymbol,client_id,supplier_id,issue_date,tax_date,due_date,currency_id,status,total_without_vat,total_with_vat) VALUES ('invoice',?,?,?,'2097-01-01','2097-01-01','2097-01-15',?,'issued',49.99,49.99)")->execute([$vs, $this->client, $this->supplier, $this->currency]);
        $this->invoice = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'SYN-COMMIT.gpc',?,'19-1000000005','0100','CZK','2097-01-15')")->execute([$this->supplier, hash('sha256', 'synthetic-commit-' . $this->supplier)]);
        $this->statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,variable_symbol,match_status) VALUES (?,'2097-01-15',49.99,'CZK',?,'unmatched')")->execute([$this->statement, $vs]);
        $this->transaction = (int) $pdo->lastInsertId();
        $pdo->commit();
        $this->transport = new CommitBoundaryTransport($pdo, $this->observer->pdo(), $this->invoice);
        $config = new Config(array_replace_recursive($c->get(Config::class)->all(), ['smtp' => ['from_email' => 'sender@example.test', 'from_name' => 'Synthetic', 'dkim' => ['enabled' => false], 'rate_limit' => ['enabled' => false, 'drain_on_cli' => false]]]));
        $mailer = new Mailer($config, new NullLogger(), $this->db, $c->get(EmailTemplateRepository::class));
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $this->transport);
        $this->mailer = $mailer;
        $thanks = $this->thanks = self::withDependency($c->get(PaymentThanksMailer::class), 'mailer', $mailer);
        $this->matcher = self::withDependency($c->get(StatementMatcher::class), 'paymentThanks', $thanks);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if ($this->supplier > 0) {
            $pdo = $this->db->pdo();
            $pdo->prepare("DELETE FROM mail_outbox WHERE JSON_VALUE(payload,'$.invoice_id')=? OR JSON_VALUE(payload,'$.vars.invoice.id')=?")->execute([$this->invoice, $this->invoice]);
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id=?')->execute([$this->supplier]);
            $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$this->invoice]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id=?')->execute([$this->statement]);
            $pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$this->client]);
            $pdo->prepare('DELETE FROM currencies WHERE id=?')->execute([$this->currency]);
            $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplier]);
        }
        if (isset($this->observer)) $this->observer->close();
    }

    public static function boundaries(): array { return ['matcher owns transaction' => [false], 'caller owns transaction' => [true]]; }

    #[DataProvider('boundaries')]
    public function testDeliveryCannotEscapeAnUncommittedPayment(bool $callerOwns): void
    {
        $pdo = $this->db->pdo();
        if ($callerOwns) $pdo->beginTransaction();
        $result = $this->matcher->match($this->transaction);
        self::assertSame('auto_exact', $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
        if ($callerOwns) {
            self::assertTrue($pdo->inTransaction());
            $pdo->rollBack();
            $stmt = $pdo->prepare('SELECT status,payment_thanks_sent_at FROM invoices WHERE id=?'); $stmt->execute([$this->invoice]);
            self::assertSame(['status' => 'issued', 'payment_thanks_sent_at' => null], $stmt->fetch(\PDO::FETCH_ASSOC));
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=?'); $stmt->execute([$this->invoice]);
            self::assertSame(0, (int) $stmt->fetchColumn());
            self::assertSame([], $this->transport->deliveries, 'A caller rollback must not leave an already delivered thanks email.');
        } else {
            self::assertFalse($pdo->inTransaction());
            self::assertSame([['in_transaction' => false, 'committed_invoice_status' => 'paid']], $this->transport->deliveries, 'Transport must see the outermost committed payment.');
        }
    }

    public function testCallerCommitIsDurableAndConsumerUsesCommittedStateOnly(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        self::assertSame('auto_exact', $this->matcher->match($this->transaction)['status']);
        self::assertCount(1, $this->intents());
        self::assertSame([], $this->transport->deliveries);
        self::assertSame(0, $this->thanks->flushDeferred($this->invoice)['sent']);
        $outside = $this->observer->pdo()->prepare("SELECT COUNT(*) FROM mail_outbox WHERE JSON_VALUE(payload,'$.invoice_id')=?");
        $outside->execute([$this->invoice]);
        self::assertSame(0, (int) $outside->fetchColumn());
        $pdo->commit();
        $fresh = self::withDependency(Bootstrap::buildContainer()->get(PaymentThanksMailer::class), 'mailer', $this->mailer);
        self::assertSame(1, $fresh->flushDeferred($this->invoice)['sent']);
        self::assertSame(0, $fresh->flushDeferred($this->invoice)['sent']);
        self::assertSame([['in_transaction' => false, 'committed_invoice_status' => 'paid']], $this->transport->deliveries);
        self::assertSame('sent', $this->intents()[0]['status']);
    }

    public function testSavepointRollbackRemovesIntentWithoutCallingAnyRenderingDependency(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $pdo->exec('SAVEPOINT thanks_test');
        $class = new \ReflectionClass(PaymentThanksMailer::class);
        $bare = $class->newInstanceWithoutConstructor();
        $class->getProperty('db')->setValue($bare, $this->db);
        $result = $bare->sendForInvoice($this->invoice, 'manual');
        self::assertSame('queued', $result['status'], 'No repository, recipient, vars, PDF or mail dependency exists in this enqueue-only instance.');
        $payload = json_decode($this->intents()[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('vars', $payload);
        self::assertArrayNotHasKey('attachments', $payload);
        self::assertArrayNotHasKey('to', $payload);
        $pdo->exec('ROLLBACK TO SAVEPOINT thanks_test');
        $pdo->commit();
        self::assertSame([], $this->intents());
        self::assertSame(0, $this->thanks->flushDeferred($this->invoice)['sent']);
        self::assertSame([], $this->transport->deliveries);
    }

    public static function queueFailures(): array { return ['ordinary' => [1644], 'changed record' => [1020], 'deadlock' => [1213]]; }

    #[DataProvider('queueFailures')]
    public function testFailedIntentInsertRollsBackMatchWithoutSmtp(int $errno): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("CREATE TRIGGER thanks_queue_failure BEFORE INSERT ON mail_outbox FOR EACH ROW BEGIN IF NEW.template='__payment_thanks_intent_v1' THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO=" . $errno . ",MESSAGE_TEXT='Synthetic enqueue failure'; END IF; END");
        $pdo->beginTransaction();
        try {
            try { $this->matcher->match($this->transaction); self::fail('Queue insert must not degrade to SMTP or a queued success.'); }
            catch (\PDOException $e) { self::assertSame($errno, (int) $e->errorInfo[1]); }
            self::assertSame($errno === 1644, $pdo->inTransaction());
            self::assertSame([], $this->transport->deliveries);
            $stmt = $pdo->prepare('SELECT status FROM invoices WHERE id=?'); $stmt->execute([$this->invoice]);
            self::assertSame('issued', $stmt->fetchColumn());
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=?'); $stmt->execute([$this->invoice]);
            self::assertSame(0, (int) $stmt->fetchColumn());
            self::assertSame([], $this->intents());
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('DROP TRIGGER thanks_queue_failure');
        }
    }

    public static function invalidCommittedState(): array { return ['unpaid' => ['unpaid'], 'changed owner' => ['owner'], 'disabled' => ['disabled']]; }

    #[DataProvider('invalidCommittedState')]
    public function testConsumerRechecksTenantPaidAndSettings(string $change): void
    {
        $pdo = $this->db->pdo(); $pdo->beginTransaction();
        self::assertSame('auto_exact', $this->matcher->match($this->transaction)['status']);
        $pdo->commit();
        if ($change === 'unpaid') $pdo->prepare("UPDATE invoices SET status='issued' WHERE id=?")->execute([$this->invoice]);
        if ($change === 'disabled') $pdo->prepare('UPDATE supplier SET payment_thanks_enabled=0 WHERE id=?')->execute([$this->supplier]);
        if ($change === 'owner') {
            $other = (int) $pdo->query('SELECT id FROM supplier WHERE id<>' . $this->supplier . ' ORDER BY id LIMIT 1')->fetchColumn();
            $pdo->prepare('UPDATE invoices SET supplier_id=? WHERE id=?')->execute([$other, $this->invoice]);
        }
        $result = $this->thanks->flushDeferred($this->invoice);
        self::assertSame(0, $result['sent']);
        self::assertSame([], $this->transport->deliveries);
        self::assertSame($change === 'owner' ? 'failed' : 'requeued', $this->intents()[0]['status']);
    }

    public function testGenericOutboxCannotConsumeIntentAndTransientFailureRetries(): void
    {
        $pdo = $this->db->pdo(); $pdo->beginTransaction();
        self::assertSame('auto_exact', $this->matcher->match($this->transaction)['status']);
        $pdo->commit();
        $generic = new \MyInvoice\Service\Mail\RateLimit\MailOutbox($pdo, new NullLogger());
        self::assertNotContains((int) $this->intents()[0]['id'], array_column($generic->due(new \DateTimeImmutable('+1 minute')), 'id'));
        $this->transport->failNext = true;
        self::assertSame(1, $this->thanks->flushDeferred($this->invoice)['failed']);
        self::assertSame('pending', $this->intents()[0]['status']);
        self::assertSame(1, (int) $this->intents()[0]['attempts']);
        self::assertSame([], $this->transport->deliveries);
        $pdo->prepare('UPDATE mail_outbox SET not_before=NOW(3) WHERE id=?')->execute([$this->intents()[0]['id']]);
        self::assertSame(1, $this->thanks->flushDeferred($this->invoice)['sent']);
        self::assertCount(1, $this->transport->deliveries);
    }

    public function testDirectSenderAndWorkerShareInvoiceLockAndRespectResend(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET status='paid' WHERE id=?")->execute([$this->invoice]);
        $name = NamedLockName::for($this->db, 'payment_thanks', $this->invoice);
        $lock = $this->observer->pdo()->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$name]);
        self::assertSame(1, (int) $lock->fetchColumn());
        try {
            $queued = $this->thanks->sendForInvoice($this->invoice, 'bank_match', requireUnsent: true);
            self::assertSame('queued', $queued['status']);
            self::assertSame('delivery_busy', $queued['reason']);
            self::assertSame(1, $this->thanks->flushDeferred($this->invoice)['queued']);
            self::assertSame([], $this->transport->deliveries);
        } finally { $this->observer->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
        self::assertSame('sent', $this->thanks->sendForInvoice($this->invoice, 'bank_match', requireUnsent: true)['status']);
        self::assertSame(1, $this->thanks->flushDeferred($this->invoice)['skipped']);
        self::assertCount(1, $this->transport->deliveries);
        self::assertSame('sent', $this->thanks->sendForInvoice($this->invoice, 'manual', requireUnsent: false)['status']);
        self::assertCount(2, $this->transport->deliveries);
    }

    public function testRateLimitTransfersIntentWithoutReportingSmtpDelivery(): void
    {
        $pdo = $this->db->pdo(); $pdo->beginTransaction();
        self::assertSame('auto_exact', $this->matcher->match($this->transaction)['status']);
        $pdo->commit();
        $counter = $this->createStub(\MyInvoice\Service\Mail\RateLimit\MailSendCounterInterface::class);
        $counter->method('sentSince')->willReturn(1);
        $counter->method('oldestSince')->willReturn(new \DateTimeImmutable());
        $config = new Config(['smtp' => ['rate_limit' => ['enabled' => true, 'per_hour' => 1]]]);
        $limiter = new \MyInvoice\Service\Mail\RateLimit\MailRateLimiter($config, $counter, new NullLogger());
        (new \ReflectionProperty(Mailer::class, 'throttle'))->setValue($this->mailer, $limiter);
        self::assertSame(1, $this->thanks->flushDeferred($this->invoice)['queued']);
        self::assertSame('requeued', $this->intents()[0]['status']);
        self::assertSame('rate_limit', $this->intents()[0]['last_error']);
        self::assertSame([], $this->transport->deliveries);
        $stmt = $pdo->prepare("SELECT id FROM mail_outbox WHERE template='invoice_payment_thanks' AND JSON_VALUE(payload,'$.vars.invoice.id')=?");
        $stmt->execute([$this->invoice]); $queuedId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $queuedId);
        $pdo->prepare('UPDATE mail_outbox SET not_before=NOW(3) WHERE id=?')->execute([$queuedId]);
        (new \ReflectionProperty(Mailer::class, 'throttle'))->setValue($this->mailer, null);
        self::assertSame(1, $this->mailer->flushDeferred()['sent']);
        self::assertSame(0, $this->thanks->flushDeferred($this->invoice)['sent']);
        self::assertCount(1, $this->transport->deliveries);
    }

    public function testFailedOutboxAcknowledgementAfterDeliveryDoesNotRepeatAcceptedRequest(): void
    {
        $pdo = $this->db->pdo(); $pdo->beginTransaction();
        self::assertSame('auto_exact', $this->matcher->match($this->transaction)['status']);
        $pdo->commit();
        $pdo->exec("CREATE TRIGGER thanks_ack_failure BEFORE UPDATE ON mail_outbox FOR EACH ROW BEGIN IF NEW.template='__payment_thanks_intent_v1' AND NEW.status='sent' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic acknowledgement failure'; END IF; END");
        try {
            try { $this->thanks->flushDeferred($this->invoice); self::fail('Acknowledgement must fail.'); }
            catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
            self::assertCount(1, $this->transport->deliveries);
            self::assertSame('pending', $this->intents()[0]['status']);
        } finally { $pdo->exec('DROP TRIGGER thanks_ack_failure'); }
        self::assertSame(1, $this->thanks->flushDeferred($this->invoice)['skipped']);
        self::assertCount(1, $this->transport->deliveries);
    }

    public function testCronConsumerIsScheduledWithBothPlatformEntrypoints(): void
    {
        $job = array_values(array_filter(\MyInvoice\Service\Cron\CronCatalog::dispatchable(), static fn (array $row): bool => $row['script'] === 'cron-payment-thanks'));
        self::assertCount(1, $job);
        self::assertSame('* * * * *', $job[0]['linux_cron']);
        self::assertSame('/sc minute /mo 1', $job[0]['windows_schtasks']);
        $root = dirname(__DIR__, 4);
        foreach (['api/bin/cron-payment-thanks.php', 'cmd/cron-payment-thanks.cmd', 'cmd/cron-payment-thanks.sh'] as $path) self::assertFileExists($root . '/' . $path);
    }

    #[DataProvider('queueFailures')]
    public function testDirectEnqueuePreservesCallerTransactionFailureSemantics(int $errno): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("CREATE TRIGGER thanks_direct_failure BEFORE INSERT ON mail_outbox FOR EACH ROW BEGIN IF NEW.template='__payment_thanks_intent_v1' THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO=" . $errno . ",MESSAGE_TEXT='Synthetic enqueue failure'; END IF; END");
        $pdo->beginTransaction();
        try {
            try { $this->thanks->sendForInvoice($this->invoice, 'manual'); self::fail('Queue insert must fail.'); }
            catch (\PDOException $e) { self::assertSame($errno, (int) $e->errorInfo[1]); }
            self::assertSame($errno === 1644, $pdo->inTransaction());
            self::assertSame([], $this->transport->deliveries);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('DROP TRIGGER thanks_direct_failure');
        }
    }

    public function testOwnCommittedMatchSurvivesSmtpFailureAndStopsAfterFiveAttempts(): void
    {
        $pdo = $this->db->pdo();
        $this->transport->failNext = true;
        self::assertSame('auto_exact', $this->matcher->match($this->transaction)['status']);
        self::assertFalse($pdo->inTransaction());
        $stmt = $this->observer->pdo()->prepare('SELECT status FROM invoices WHERE id=?'); $stmt->execute([$this->invoice]);
        self::assertSame('paid', $stmt->fetchColumn());
        self::assertSame(1, (int) $this->intents()[0]['attempts']);
        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $pdo->prepare('UPDATE mail_outbox SET not_before=NOW(3) WHERE id=?')->execute([$this->intents()[0]['id']]);
            $this->transport->failNext = true;
            self::assertSame(1, $this->thanks->flushDeferred($this->invoice)['failed']);
            self::assertSame($attempt, (int) $this->intents()[0]['attempts']);
        }
        self::assertSame('failed', $this->intents()[0]['status']);
        self::assertSame(0, $this->thanks->flushDeferred($this->invoice)['failed']);
        self::assertSame([], $this->transport->deliveries);
    }

    public static function postCommitFailures(): array { return ['acknowledgement' => [false], 'retry scheduling' => [true]]; }

    #[DataProvider('postCommitFailures')]
    public function testAutomaticFlushPersistenceFailureCannotUndoCommittedMatch(bool $smtpFails): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("CREATE TRIGGER thanks_postcommit_failure BEFORE UPDATE ON mail_outbox FOR EACH ROW BEGIN IF NEW.template='__payment_thanks_intent_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic post-commit persistence failure'; END IF; END");
        $error = null;
        $result = null;
        try {
            $this->transport->failNext = $smtpFails;
            try { $result = $this->matcher->match($this->transaction); }
            catch (\Throwable $e) { $error = $e; }
            self::assertFalse($pdo->inTransaction());
            $observer = $this->observer->pdo();
            $stmt = $observer->prepare('SELECT status FROM invoices WHERE id=?'); $stmt->execute([$this->invoice]);
            self::assertSame('paid', $stmt->fetchColumn());
            $stmt = $observer->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=?'); $stmt->execute([$this->invoice]);
            self::assertSame(1, (int) $stmt->fetchColumn());
            $stmt = $observer->prepare('SELECT match_status FROM bank_transactions WHERE id=?'); $stmt->execute([$this->transaction]);
            self::assertSame('auto_exact', $stmt->fetchColumn());
            $stmt = $observer->prepare("SELECT status FROM mail_outbox WHERE template=? AND JSON_VALUE(payload,'$.invoice_id')=?");
            $stmt->execute([PaymentThanksOutbox::TEMPLATE, $this->invoice]);
            self::assertSame(['pending'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
            self::assertCount($smtpFails ? 0 : 1, $this->transport->deliveries);
            $this->transport->failNext = $smtpFails;
            try { $this->thanks->flushDeferred($this->invoice); self::fail('Explicit consumer must still report persistence failure.'); }
            catch (\PDOException $e) { self::assertSame('45000', $e->errorInfo[0]); }
            self::assertNull($error, 'The payment is committed; automatic delivery persistence cannot turn matching into an exception.');
            self::assertSame('auto_exact', $result['status']);
        } finally { $pdo->exec('DROP TRIGGER thanks_postcommit_failure'); }
        $report = $this->thanks->flushDeferred($this->invoice);
        self::assertSame(1, $report[$smtpFails ? 'sent' : 'skipped']);
        self::assertCount(1, $this->transport->deliveries);
        self::assertSame(0, $this->thanks->flushDeferred($this->invoice)['sent']);
    }

    private function intents(): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM mail_outbox WHERE template=? AND JSON_VALUE(payload,'$.invoice_id')=? ORDER BY id");
        $stmt->execute([PaymentThanksOutbox::TEMPLATE, $this->invoice]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function withDependency(object $original, string $name, object $replacement): object
    {
        $class = new \ReflectionClass($original);
        $args = [];
        foreach ($class->getConstructor()->getParameters() as $parameter) $args[] = $parameter->getName() === $name ? $replacement : $class->getProperty($parameter->getName())->getValue($original);
        return $class->newInstanceArgs($args);
    }
}

final class CommitBoundaryTransport implements TransportInterface
{
    public array $deliveries = [];
    public bool $failNext = false;
    public function __construct(private readonly \PDO $writer, private readonly \PDO $observer, private readonly int $invoice) {}
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failNext) { $this->failNext = false; throw new \RuntimeException('Synthetic transport retry'); }
        $stmt = $this->observer->prepare('SELECT status FROM invoices WHERE id=?'); $stmt->execute([$this->invoice]);
        $this->deliveries[] = ['in_transaction' => $this->writer->inTransaction(), 'committed_invoice_status' => $stmt->fetchColumn()];
        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }
    public function __toString(): string { return 'synthetic://commit-boundary'; }
}
