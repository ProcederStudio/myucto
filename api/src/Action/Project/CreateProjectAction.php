<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateProjectAction
{
    public function __construct(
        private readonly ProjectRepository $repo,
        private readonly ClientRepository $clients,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!RequestAuthorization::allows($request, 'clients')) {
            return Json::error($response, 'forbidden_permission', 'Vytvoření zakázky vyžaduje čtení klientů.', 403);
        }
        if (RequestAuthorization::isClientType($request) && !empty($body['default_revenue_category_id'])) {
            return Json::error($response, 'forbidden_permission', 'Klasifikaci tržeb spravuje interní role.', 403);
        }

        $errors = Validation::project($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Klient musí existovat A patřit aktuálnímu supplier
        if (!SupplierGuard::owns($request, $this->clients->find((int) $body['client_id']))) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }

        try {
            $id = $this->repo->create($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('project.created', $user['id'] ?? null, 'project', $id, [
            'client_id' => $body['client_id'],
            'name'      => $body['name'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id), 201);
    }
}
