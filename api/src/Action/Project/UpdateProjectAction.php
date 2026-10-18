<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateProjectAction
{
    public function __construct(
        private readonly ProjectRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (RequestAuthorization::isClientType($request) && array_key_exists('default_revenue_category_id', $body)
            && (int) $body['default_revenue_category_id'] !== (int) ($existing['default_revenue_category_id'] ?? 0)) {
            return Json::error($response, 'forbidden_permission', 'Klasifikaci tržeb spravuje interní role.', 403);
        }
        if (RequestAuthorization::isClientType($request)) unset($body['default_revenue_category_id']);

        // client_id se nemění při update — vždy z existujícího záznamu
        $body['client_id'] = $existing['client_id'];

        // Validuje se zakázka po sloučení s uloženými hodnotami — chybějící klíč = beze změny (#113).
        $errors = Validation::project($this->repo->mergeWithStored($existing, $body));
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        try {
            $backfilled = $this->repo->update($id, $body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('project.updated', $user['id'] ?? null, 'project', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        // revenue_category_backfilled = počet vydaných faktur, do kterých byla doplněna
        // nově nastavená výchozí kategorie tržby zakázky (frontend ukáže toast).
        $project = $this->repo->find($id) ?? [];
        $project['revenue_category_backfilled'] = $backfilled;
        return Json::ok($response, $project);
    }
}
