<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Accounting\DimensionDefaultsAction;
use MyInvoice\Action\Project\CreateProjectAction;
use MyInvoice\Action\Project\GetProjectAction;
use MyInvoice\Action\Project\ListProjectsAction;
use MyInvoice\Action\Project\ProjectProfitAction;
use MyInvoice\Action\Project\ProjectStatsAction;
use MyInvoice\Action\Project\UpdateProjectAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class ProjectDelegationTest extends StockTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->db)) foreach ($this->supplierIds as $sid) {
            $this->db->pdo()->prepare('DELETE p FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ?')->execute([$sid]);
        }
        if (isset($this->db)) foreach ($this->supplierIds as $sid) {
            $this->db->pdo()->prepare('DELETE FROM revenue_categories WHERE supplier_id = ?')->execute([$sid]);
        }
        parent::tearDown();
    }

    public function testInvoiceGrantControlsEmbeddedAggregatesAndStatistics(): void
    {
        [$sid, $id] = $this->project();
        $this->db->pdo()->prepare('INSERT INTO project_revenue_cache (project_id, currency_id, revenue, invoice_count) VALUES (?, ?, 12345, 3)')
            ->execute([$id, $this->currencyIdFor($sid)]);
        $list = $this->container->get(ListProjectsAction::class);
        $get = $this->container->get(GetProjectAction::class);
        $stats = $this->container->get(ProjectStatsAction::class);
        $without = $this->request($sid);
        $with = $this->request($sid, ['invoices' => 1]);
        self::assertSame(12345.0, (float) $this->json($list($with, new Response(), []))['data'][0]['revenue']);
        foreach ([$without, $with, $without] as $request) {
            $canRead = $request === $with;
            $row = $this->json($list($request, new Response(), []))['data'][0];
            self::assertSame($canRead, array_key_exists('revenue', $row));
            self::assertSame($canRead, array_key_exists('invoice_count', $row));
            $detail = $this->json($get($request, new Response(), ['id' => $id]));
            foreach (['invoices_count', 'revenue_by_month', 'revenue_by_year', 'unpaid_summary'] as $key) {
                self::assertSame($canRead, array_key_exists($key, $detail), $key);
            }
            self::assertSame($canRead ? 200 : 403, $stats($request, new Response())->getStatusCode());
        }
        $foreign = $this->createSupplier();
        self::assertSame(404, $get($this->request($foreign), new Response(), ['id' => $id])->getStatusCode());
        self::assertSame([], $this->json($list($this->request($foreign), new Response(), []))['data']);
    }

    public function testClientCannotReadProfitEvenWithOperationalAndInvoiceGrants(): void
    {
        [$sid, $id] = $this->project();
        $action = $this->container->get(ProjectProfitAction::class);
        $request = $this->request($sid, ['invoices' => 2, 'projects' => 2]);
        self::assertSame(403, $action->overview($request, new Response())->getStatusCode());
        self::assertSame(403, $action->detail($request, new Response(), ['id' => $id])->getStatusCode());
    }

    public function testClientProjectGrantDoesNotAllowAccountingDimensions(): void
    {
        [$sid, $id] = $this->project();
        $this->db->pdo()->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?')->execute([$sid]);
        $action = $this->container->get(DimensionDefaultsAction::class);
        $request = $this->request($sid, ['projects' => 2]);
        self::assertSame(403, $action->getProject($request, new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(403, $action->saveProject($request->withParsedBody(['dimensions' => []]), new Response(), ['id' => $id])->getStatusCode());
        $staff = $request->withAttribute('auth.effective_role', new EffectiveRole(0, 'Interní zakázky', 'staff', true, ['projects' => 2], 'custom'));
        self::assertSame(200, $action->getProject($staff, new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(200, $action->saveProject($staff->withParsedBody(['dimensions' => []]), new Response(), ['id' => $id])->getStatusCode());
    }

    public function testOperationalEditWorksWithoutClientReadAndCannotChangeClassification(): void
    {
        [$sid, $id] = $this->project();
        $action = $this->container->get(UpdateProjectAction::class);
        $request = $this->request($sid, ['projects' => 2]);
        $updated = $action($request->withParsedBody(['note' => 'Provozní poznámka']), new Response(), ['id' => $id]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame('Provozní poznámka', $this->json($updated)['note']);
        $this->db->pdo()->prepare("INSERT INTO revenue_categories (supplier_id, code, label) VALUES (?, 'delegace', 'Syntetická kategorie')")->execute([$sid]);
        $category = (int) $this->db->pdo()->lastInsertId();
        $denied = $action($request->withParsedBody(['default_revenue_category_id' => $category]), new Response(), ['id' => $id]);
        self::assertSame(403, $denied->getStatusCode());
        self::assertNull($this->container->get(ProjectRepository::class)->find($id)['default_revenue_category_id']);
    }

    public function testCreationRequiresClientReadAndTenantRelationship(): void
    {
        $sid = $this->createSupplier();
        $client = $this->client($sid);
        $foreign = $this->client($this->createSupplier());
        $action = $this->container->get(CreateProjectAction::class);
        $body = ['client_id' => $client, 'name' => 'Delegovaná zakázka', 'payment_due_days' => 7, 'currency_id' => $this->currencyIdFor($sid)];
        self::assertSame(403, $action($this->request($sid)->withParsedBody($body), new Response())->getStatusCode());
        $request = $this->request($sid, ['clients' => 1, 'projects.create' => 2]);
        self::assertSame(400, $action($request->withParsedBody(array_replace($body, ['client_id' => $foreign])), new Response())->getStatusCode());
        $created = $action($request->withParsedBody($body), new Response());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame($client, $this->json($created)['client_id']);
    }

    private function project(): array
    {
        $sid = $this->createSupplier();
        $id = $this->container->get(ProjectRepository::class)->create([
            'client_id' => $this->client($sid), 'name' => 'Syntetická zakázka', 'currency_id' => $this->currencyIdFor($sid),
        ]);
        return [$sid, $id];
    }

    private function request(int $sid, array $extra = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/projects')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Delegovaná role', 'client', true, $extra + ['projects' => 1], 'custom'));
    }

    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
