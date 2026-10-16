<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\WorkReport\WorkReportLinkService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Autentizované endpointy pro správu sledovacího odkazu na výkaz práce
 * (tlačítko „Poslat odkaz na sledování výkazu práce" v detailu klienta/zakázky).
 *
 *   GET    /api/clients|projects/{id}/work-report-link             — stav odkazu
 *   GET    /api/clients|projects/{id}/work-report-link/recipients  — předvyplnění příjemců
 *   POST   /api/clients|projects/{id}/work-report-link/send        — odeslat odkaz e-mailem
 *   DELETE /api/clients|projects/{id}/work-report-link             — zneplatnit odkaz
 *
 * RBAC řeší PermissionMiddleware dle cesty (clients/projects): GET i pro readonly,
 * POST/DELETE jen accountant+admin.
 */
final class WorkReportLinkAction
{
    public function __construct(
        private readonly WorkReportLinkService $service,
        private readonly ClientRepository $clients,
        private readonly ProjectRepository $projects,
        private readonly RecipientResolver $recipients,
        private readonly Mailer $mailer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function getClient(Request $r, Response $w, array $a): Response     { return $this->status($r, $w, 'client', (int) ($a['id'] ?? 0)); }
    public function getProject(Request $r, Response $w, array $a): Response    { return $this->status($r, $w, 'project', (int) ($a['id'] ?? 0)); }
    public function sendClient(Request $r, Response $w, array $a): Response    { return $this->send($r, $w, 'client', (int) ($a['id'] ?? 0)); }
    public function sendProject(Request $r, Response $w, array $a): Response   { return $this->send($r, $w, 'project', (int) ($a['id'] ?? 0)); }
    public function revokeClient(Request $r, Response $w, array $a): Response  { return $this->revoke($r, $w, 'client', (int) ($a['id'] ?? 0)); }
    public function revokeProject(Request $r, Response $w, array $a): Response { return $this->revoke($r, $w, 'project', (int) ($a['id'] ?? 0)); }
    public function recipientsClient(Request $r, Response $w, array $a): Response  { return $this->recipientsFor($r, $w, 'client', (int) ($a['id'] ?? 0)); }
    public function recipientsProject(Request $r, Response $w, array $a): Response { return $this->recipientsFor($r, $w, 'project', (int) ($a['id'] ?? 0)); }

    /**
     * Ověří vlastnictví a vrátí kontext entity.
     *
     * @return array{supplier_id:int, client_id:int, project_id:?int,
     *               client_company_name:string, project_name:?string,
     *               language:string, client_main_email:string}|null
     */
    private function resolveEntity(Request $request, string $scope, int $id): ?array
    {
        $sid = SupplierGuard::currentId($request);
        if ($id <= 0 || $sid <= 0) {
            return null;
        }

        if ($scope === 'project') {
            $project = $this->projects->find($id);
            if ($project === null || (int) ($project['supplier_id'] ?? 0) !== $sid) {
                return null;
            }
            $client = $this->clients->find((int) $project['client_id']);
            if ($client === null) {
                return null;
            }
            return [
                'supplier_id'         => $sid,
                'client_id'           => (int) $project['client_id'],
                'project_id'          => $id,
                'client_company_name' => (string) ($client['company_name'] ?? ''),
                'project_name'        => (string) ($project['name'] ?? ''),
                'language'            => in_array($client['language'] ?? 'cs', ['cs', 'en'], true) ? (string) $client['language'] : 'cs',
                'client_main_email'   => (string) ($client['main_email'] ?? ''),
            ];
        }

        $client = $this->clients->find($id);
        if ($client === null || (int) ($client['supplier_id'] ?? 0) !== $sid) {
            return null;
        }
        return [
            'supplier_id'         => $sid,
            'client_id'           => $id,
            'project_id'          => null,
            'client_company_name' => (string) ($client['company_name'] ?? ''),
            'project_name'        => null,
            'language'            => in_array($client['language'] ?? 'cs', ['cs', 'en'], true) ? (string) $client['language'] : 'cs',
            'client_main_email'   => (string) ($client['main_email'] ?? ''),
        ];
    }

    private function status(Request $request, Response $response, string $scope, int $id): Response
    {
        $ctx = $this->resolveEntity($request, $scope, $id);
        if ($ctx === null) {
            return Json::error($response, 'not_found', 'Entita nenalezena.', 404);
        }
        $link = $this->service->findActiveForEntity($ctx['supplier_id'], $scope, $ctx['client_id'], $ctx['project_id']);
        if ($link === null) {
            return Json::ok($response, ['exists' => false]);
        }
        return Json::ok($response, [
            'exists'         => true,
            'url'            => $this->service->publicUrl($link),
            'last_sent_at'   => $link['last_sent_at'] ?? null,
            'last_viewed_at' => $link['last_viewed_at'] ?? null,
        ]);
    }

    private function recipientsFor(Request $request, Response $response, string $scope, int $id): Response
    {
        $ctx = $this->resolveEntity($request, $scope, $id);
        if ($ctx === null) {
            return Json::error($response, 'not_found', 'Entita nenalezena.', 404);
        }
        // Syntetická faktura pro RecipientResolver (#86): e-maily klienta + e-maily
        // zakázky (jen project scope). Bez kopie dodavateli — odkaz jde klientovi.
        $invoice = [
            'client_id'         => $ctx['client_id'],
            'client_main_email' => $ctx['client_main_email'],
            'project_id'        => $ctx['project_id'],
            'supplier_id'       => $ctx['supplier_id'],
        ];
        $r = $this->recipients->resolve(RecipientResolver::TYPE_DOCUMENTS, $invoice, false);
        return Json::ok($response, [
            'to'       => $r['to'],
            'cc'       => $r['cc'],
            'bcc'      => $r['bcc'],
            'resolved' => $r['resolved'],
        ]);
    }

    private function send(Request $request, Response $response, string $scope, int $id): Response
    {
        $ctx = $this->resolveEntity($request, $scope, $id);
        if ($ctx === null) {
            return Json::error($response, 'not_found', 'Entita nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $to   = $this->cleanEmails($body['to'] ?? []);
        $cc   = $this->cleanEmails($body['cc'] ?? []);
        $bcc  = $this->cleanEmails($body['bcc'] ?? []);
        $note = trim((string) ($body['note'] ?? ''));
        if ($note !== '' && mb_strlen($note) > 2000) {
            $note = mb_substr($note, 0, 2000);
        }
        if ($to === []) {
            return Json::error($response, 'no_recipients', 'Zadejte alespoň jednoho příjemce.', 422);
        }

        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        $link = $this->service->getOrCreate($ctx['supplier_id'], $scope, $ctx['client_id'], $ctx['project_id'], $userId);
        $url  = $this->service->publicUrl($link);

        $vars = [
            'tracking_url'        => $url,
            'scope'              => $scope,
            'client_company_name' => $ctx['client_company_name'],
            'project_name'        => $ctx['project_name'],
            'note'                => $note !== '' ? $note : null,
            'supplier'            => $this->service->loadSupplierVars($ctx['supplier_id']),
        ];

        try {
            $this->mailer->sendTemplate(
                'work_report_link',
                $ctx['language'],
                $to,
                $vars,
                null,
                $cc,
                $bcc,
                [],
                $userId,
                templateSupplierId: (int) $ctx['supplier_id'],
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'send_failed', 'E-mail se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $this->service->touchSent((int) $link['id']);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('work_report_link.sent', $userId, 'work_report_link', (int) $link['id'], [
            'scope' => $scope,
            'to'    => $to,
            'cc'    => $cc,
            'bcc'   => $bcc,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to' => $to,
            'sent_at' => date('Y-m-d H:i:s'),
            'url'     => $url,
        ]);
    }

    private function revoke(Request $request, Response $response, string $scope, int $id): Response
    {
        $ctx = $this->resolveEntity($request, $scope, $id);
        if ($ctx === null) {
            return Json::error($response, 'not_found', 'Entita nenalezena.', 404);
        }
        $link = $this->service->findActiveForEntity($ctx['supplier_id'], $scope, $ctx['client_id'], $ctx['project_id']);
        if ($link === null) {
            return Json::ok($response, ['ok' => true, 'exists' => false]);
        }
        $this->service->revoke((int) $link['id']);

        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('work_report_link.revoked', $userId, 'work_report_link', (int) $link['id'], [
            'scope' => $scope,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true, 'exists' => false]);
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private function cleanEmails($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $e) {
            $e = trim((string) $e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[mb_strtolower($e)] = $e;
            }
        }
        return array_values($out);
    }
}
