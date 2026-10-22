<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\AboDomesticPaymentParser;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionService;
use MyInvoice\Service\Bank\Connector\CsasApiClient;
use MyInvoice\Service\Bank\Connector\CsasBankConnector;
use MyInvoice\Service\Bank\Connector\CsasCredentialVault;
use MyInvoice\Service\Bank\Connector\CsasTransactionParser;
use MyInvoice\Service\Bank\Connector\StoredBankPaymentSubmissionJournal;
use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Odeslání do České spořitelny po položkách nad skutečnou evidencí. API banky nemá
 * idempotenční klíč, proto opakované odeslání po pádu nesmí žádnou platbu založit
 * podruhé: pokračuje se z evidence, nebo se nejasný stav zastaví k ruční kontrole.
 * Banka je atrapa a volání se počítají.
 *
 * Odeslání se zakládá mimo databázovou transakci (begin() ji odmítne), proto test
 * po sobě maže vlastní řádky.
 */
#[Group('integration')]
#[AllowMockObjectsWithoutExpectations]
final class CsasPaymentSubmissionResumeTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private BankPaymentOrderSubmissionRepository $repository;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $connectionId = 0;
    private int $orderId = 0;
    private string $payerIban = '';
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) $this->markTestSkipped('Testovací DB nemá firmu.');
        $this->repository = new BankPaymentOrderSubmissionRepository($this->db);
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                account_number, bank_code) VALUES (?, 'XTS', 'XTS', 'X', 'Test', 'Test', 2, 1, 0, '1000000005', '0800')")
            ->execute([$this->supplierId]);
        $this->currencyId = (int) $this->pdo->lastInsertId();
        $this->connectionId = (new BankConnectionRepository($this->db))->ensure($this->supplierId, $this->currencyId, 'csas');
        $this->pdo->prepare("INSERT INTO payment_orders (supplier_id, currency, payer_currency_id, payer_account_number, payer_bank_code,
                payment_date, total_amount, item_count, mark_paid) VALUES (?, 'CZK', ?, '1000000005', '0800', ?, 300.00, 2, 0)")
            ->execute([$this->supplierId, $this->currencyId, date('Y-m-d')]);
        $this->orderId = (int) $this->pdo->lastInsertId();
        $this->payerIban = AboDomesticPaymentParser::czechIban('0', '1000000005', '0800');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        $this->pdo->prepare('DELETE FROM bank_payment_order_submissions WHERE supplier_id = ? AND payment_order_id = ?')
            ->execute([$this->supplierId, $this->orderId]);
        $this->pdo->prepare('DELETE FROM payment_orders WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $this->orderId]);
        $this->pdo->prepare('DELETE FROM bank_connections WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $this->connectionId]);
        $this->pdo->prepare('DELETE FROM currencies WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $this->currencyId]);
        $this->db->close();
    }

    public function testRepeatedClickAfterAcceptanceNeverCreatesPaymentsAgain(): void
    {
        $engine = $this->engine();
        $first = $engine->submit($this->supplierId, $this->orderId, $this->connectionId, null, fn (): string => $this->abo());
        self::assertTrue($first['created']);
        self::assertSame('awaiting_signature', $first['submission']['status']);
        self::assertSame('SYNTHETIC-BATCH', $first['submission']['provider_reference']);
        self::assertSame(['payment', 'payment', 'batch'], $this->calls);

        $second = $engine->submit($this->supplierId, $this->orderId, $this->connectionId, null, fn (): string => $this->abo());
        self::assertFalse($second['created']);
        self::assertSame(['payment', 'payment', 'batch'], $this->calls, 'Druhé kliknutí banku neosloví.');
        self::assertSame(['created', 'created'], array_column($this->repository->items($this->supplierId, (int) $first['submission']['id']), 'status'));
    }

    public function testCrashBetweenPaymentsAndBatchResumesWithoutCreatingPaymentsAgain(): void
    {
        $submissionId = $this->interrupted(static function (StoredBankPaymentSubmissionJournal $journal): void {
            $journal->sending(1);
            $journal->created(1, 'SYNTHETIC-SIGN-1', 'synthetic-hash-1', 'ACTC');
            $journal->sending(2);
            $journal->created(2, 'SYNTHETIC-SIGN-2', 'synthetic-hash-2', 'ACTC');
        });

        $result = $this->engine()->submit($this->supplierId, $this->orderId, $this->connectionId, null, fn (): string => $this->abo());

        self::assertTrue($result['created']);
        self::assertSame('awaiting_signature', $result['submission']['status']);
        self::assertSame(['batch'], $this->calls, 'Založené platby se nezakládají znovu, jen se dokončí dávka.');
        self::assertSame((int) $result['submission']['id'], $submissionId);
    }

    public function testCrashAfterFirstPaymentCreatesOnlyTheRemainingOne(): void
    {
        $this->interrupted(static function (StoredBankPaymentSubmissionJournal $journal): void {
            $journal->sending(1);
            $journal->created(1, 'SYNTHETIC-SIGN-1', 'synthetic-hash-1', 'ACTC');
        });

        $result = $this->engine()->submit($this->supplierId, $this->orderId, $this->connectionId, null, fn (): string => $this->abo());

        self::assertSame('awaiting_signature', $result['submission']['status']);
        self::assertSame(['payment', 'batch'], $this->calls);
    }

    public function testCrashDuringPaymentRequestStopsForManualCheck(): void
    {
        $submissionId = $this->interrupted(static function (StoredBankPaymentSubmissionJournal $journal): void {
            $journal->sending(1);
        });

        $result = $this->engine()->submit($this->supplierId, $this->orderId, $this->connectionId, null, fn (): string => $this->abo());

        self::assertTrue($result['created']);
        self::assertSame('unknown', $result['submission']['status']);
        self::assertSame('csas_payment_outcome_unknown', $result['submission']['error_code']);
        self::assertSame([], $this->calls, 'Platba s nejasným výsledkem se znovu neposílá.');
        $again = $this->engine()->submit($this->supplierId, $this->orderId, $this->connectionId, null, fn (): string => $this->abo());
        self::assertFalse($again['created']);
        self::assertSame([], $this->calls);
        self::assertSame(['sending', 'planned'], array_column($this->repository->items($this->supplierId, $submissionId), 'status'));
    }

    public function testCrashDuringBatchRequestStopsForManualCheck(): void
    {
        $this->interrupted(function (StoredBankPaymentSubmissionJournal $journal): void {
            $journal->sending(1);
            $journal->created(1, 'SYNTHETIC-SIGN-1', 'synthetic-hash-1', 'ACTC');
            $journal->sending(2);
            $journal->created(2, 'SYNTHETIC-SIGN-2', 'synthetic-hash-2', 'ACTC');
            $journal->batchSending();
        });

        $result = $this->engine()->submit($this->supplierId, $this->orderId, $this->connectionId, null, fn (): string => $this->abo());

        self::assertSame('unknown', $result['submission']['status']);
        self::assertSame([], $this->calls, 'Dávka s nejasným výsledkem se nezakládá podruhé.');
    }

    public function testChangedOrderCannotResumeInterruptedSubmission(): void
    {
        $this->interrupted(static function (StoredBankPaymentSubmissionJournal $journal): void {
            $journal->sending(1);
            $journal->created(1, 'SYNTHETIC-SIGN-1', 'synthetic-hash-1', 'ACTC');
        });

        try {
            $this->engine()->submit($this->supplierId, $this->orderId, $this->connectionId, null, fn (): string => $this->abo(25000));
            self::fail('Změněný příkaz nesmí pokračovat v přerušeném odeslání.');
        } catch (\MyInvoice\Service\Bank\Connector\BankConnectorOperationException $e) {
            self::assertSame('payment_order_changed', $e->errorCode);
        }
        self::assertSame([], $this->calls);
        self::assertSame('unknown', $this->repository->find($this->supplierId, $this->orderId)['status']);
    }

    public function testItemTransitionsAreGuardedAgainstReplay(): void
    {
        $submissionId = $this->begin();
        $items = $this->plannedItems();
        $this->repository->planItems($this->supplierId, $submissionId, $items);
        self::assertSame('creating', $this->repository->find($this->supplierId, $this->orderId)['status']);
        self::assertCount(2, $this->repository->planItems($this->supplierId, $submissionId, $items), 'Opakovaný plán položky nezdvojí.');
        $items[1]['amount_minor'] = 1;
        try {
            $this->repository->planItems($this->supplierId, $submissionId, $items);
            self::fail('Jiný plán se nesmí přijmout.');
        } catch (\DomainException $e) {
            self::assertSame('payment_plan_changed', $e->getMessage());
        }
        self::assertTrue($this->repository->moveItem($this->supplierId, $submissionId, 1, 'sending'));
        self::assertFalse($this->repository->moveItem($this->supplierId, $submissionId, 1, 'sending'), 'Položku nelze poslat dvakrát.');
        self::assertTrue($this->repository->moveItem($this->supplierId, $submissionId, 1, 'created', 'SYNTHETIC-SIGN-1', 'synthetic-hash-1', 'ACTC'));
        self::assertFalse($this->repository->moveItem($this->supplierId, $submissionId, 1, 'unknown', errorCode: 'late'));
        self::assertTrue($this->repository->markBatchSending($this->supplierId, $submissionId));
        self::assertFalse($this->repository->markBatchSending($this->supplierId, $submissionId), 'Dávku nelze zakládat dvakrát.');
    }

    /** @param callable(StoredBankPaymentSubmissionJournal):void $steps kroky, po kterých proces „spadl“ */
    private function interrupted(callable $steps): int
    {
        $submissionId = $this->begin();
        $journal = new StoredBankPaymentSubmissionJournal($this->repository, $this->supplierId, $submissionId);
        $journal->plan($this->plannedItems());
        $steps($journal);
        self::assertSame('creating', $this->repository->find($this->supplierId, $this->orderId)['status']);
        return $submissionId;
    }

    private function begin(): int
    {
        $attempt = $this->repository->begin($this->supplierId, $this->orderId, $this->connectionId, 'csas', hash('sha256', $this->abo()), null);
        return (int) $attempt['submission']['id'];
    }

    /** @return list<array{line_no:int,instruction_identification:string,amount_minor:int,creditor_iban:string}> */
    private function plannedItems(): array
    {
        $batch = (new \MyInvoice\Service\Bank\Connector\CsasAboBatchMapper())->map($this->abo(), $this->payerIban);
        return array_map(static fn (array $payment): array => [
            'line_no' => $payment['line_no'], 'instruction_identification' => $payment['instruction_identification'],
            'amount_minor' => $payment['amount_minor'], 'creditor_iban' => $payment['creditor_iban'],
        ], $batch['payments']);
    }

    private function abo(int $secondAmountMinor = 20000): string
    {
        return (new AboPaymentOrderWriter())->build([
            'client_name' => 'Synthetic Client',
            'file_number' => str_pad((string) ($this->orderId % 1000000), 6, '0', STR_PAD_LEFT),
            'payer_account_number' => '1000000005',
            'payer_bank_code' => '0800',
            'payment_date' => date('Y-m-d'),
            'items' => [
                ['account_number' => '1000000005', 'bank_code' => '0100', 'amount_minor' => 10000, 'variable_symbol' => '2026001',
                    'message' => 'Synthetic one'],
                ['account_number' => '19-1000000005', 'bank_code' => '0300', 'amount_minor' => $secondAmountMinor, 'variable_symbol' => '2026002',
                    'message' => 'Synthetic two'],
            ],
        ]);
    }

    private function engine(): BankPaymentSubmissionService
    {
        $credentials = ['version' => 1, 'supplier_id' => $this->supplierId, 'connection_id' => $this->connectionId,
            'api_key' => 'synthetic-key', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret',
            'redirect_uri' => 'https://example.invalid/callback', 'access_token' => 'synthetic-access',
            'refresh_token' => 'synthetic-refresh', 'access_expires_at' => time() + 300, 'account_id' => 'synthetic-account',
            'account_iban' => $this->payerIban, 'account_currency' => 'CZK', 'call_guard_key' => str_repeat('a', 64),
            'environment' => 'production', 'scope' => 'siblings.accounts siblings.payments', 'payment_account_suitable' => true];
        $vault = $this->createStub(CsasCredentialVault::class);
        $vault->method('decode')->willReturn($credentials);
        $vault->method('access')->willReturn($credentials);
        $api = $this->createStub(CsasApiClient::class);
        $api->method('createPayment')->willReturnCallback(function (): array {
            $this->calls[] = 'payment';
            return ['sign_id' => 'SYNTHETIC-SIGN-' . count($this->calls), 'hash' => 'synthetic-hash', 'instruction_status' => 'ACTC'];
        });
        $api->method('createBatch')->willReturnCallback(function (): array {
            $this->calls[] = 'batch';
            return ['sign_id' => 'SYNTHETIC-BATCH', 'hash' => 'synthetic-batch-hash'];
        });
        $connector = new CsasBankConnector($api, $vault, new CsasTransactionParser());

        $connections = $this->createStub(BankConnectionRepository::class);
        $connections->method('findWithCredentialById')->willReturn([
            'id' => $this->connectionId, 'supplier_id' => $this->supplierId, 'currency_id' => $this->currencyId, 'provider' => 'csas',
            'enabled' => true, 'has_token' => true, 'token_ciphertext' => 'enc:v2:synthetic', 'is_active' => true,
            'account_code' => 'CZK', 'bank_code' => '0800', 'account_number' => '1000000005', 'iban' => null,
            'verified_account_number' => '1000000005', 'verified_bank_code' => '0800', 'verified_currency' => 'CZK',
        ]);
        $calls = $this->createStub(BankConnectorCallGuard::class);
        $calls->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $cb): mixed => $cb());
        $calls->method('call')->willReturnCallback(static fn (string $t, callable $cb): mixed => $cb());
        $secrets = $this->createStub(SecretEncryption::class);
        $secrets->method('validateKey')->willReturn(null);
        $secrets->method('decryptFor')->willReturn('synthetic-token');
        return new BankPaymentSubmissionService($connections, $this->repository, new BankConnectorRegistry([$connector]), $calls, $secrets);
    }
}
