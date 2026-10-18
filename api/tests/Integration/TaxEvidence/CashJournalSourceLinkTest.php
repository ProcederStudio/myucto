<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\TaxEvidence\CashJournalAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class CashJournalSourceLinkTest extends CashJournalTestCase
{
    protected string $accountA = '1000000005';

    public function testJournalLinksBankMovementToStatementOnly(): void
    {
        $statementId = $this->statement($this->supplierId, $this->accountA);
        $transactionId = $this->bankTx($statementId, 120);
        $this->cashDoc('in', 'sale', 80);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/tax-evidence/cash-journal')
            ->withQueryParams(['year' => (string) self::YEAR])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']);
        $response = $this->container->get(CashJournalAction::class)->get($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $data['rows']);
        foreach ($data['rows'] as $row) {
            self::assertArrayHasKey('bank_statement_id', $row);
            self::assertSame($row['source_type'] === 'bank' ? $statementId : null, $row['bank_statement_id']);
            if ($row['source_type'] === 'bank') self::assertSame($transactionId, $row['source_id']);
        }
    }

    public function testBatchLinksRespectExplicitAndLegacyOwnership(): void
    {
        $foreignSupplierId = $this->cloneSupplier('tax_evidence', true);
        $legacy = $this->statement($this->supplierId, $this->accountA);
        $own = $this->statement($this->supplierId, $this->accountA);
        $foreign = $this->statement($foreignSupplierId, $this->accountA);
        $pdo = $this->db->pdo();
        $update = $pdo->prepare('UPDATE bank_statements SET supplier_id = ? WHERE id = ?');
        $update->execute([$this->supplierId, $own]);
        $update->execute([$foreignSupplierId, $foreign]);
        $legacyTx = $this->bankTx($legacy, 10);
        $ownTx = $this->bankTx($own, 20);
        $foreignTx = $this->bankTx($foreign, 30);
        $resolver = $this->container->get(BankStatementOwnershipResolver::class);
        $ids = [$legacyTx, $ownTx, $foreignTx, $ownTx, 0, -1];
        self::assertSame([$legacyTx => $legacy, $ownTx => $own], $resolver->statementIdsForTransactions($this->supplierId, $ids));
        self::assertSame([$foreignTx => $foreign], $resolver->statementIdsForTransactions($foreignSupplierId, $ids));
        // Shodný účet druhé firmy zneplatní jen legacy fallback, nikoli výslovného vlastníka.
        $this->currencyRow($foreignSupplierId, 'CZK', $this->accountA, '2010');
        self::assertSame([$ownTx => $own], $resolver->statementIdsForTransactions($this->supplierId, $ids));
        self::assertSame([], $resolver->statementIdsForTransactions($this->supplierId, []));
        self::assertSame([], $resolver->statementIdsForTransactions(0, $ids));
    }
}
