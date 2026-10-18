<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/projects/{id}
 * Tvrdé smazání. Selže (409), pokud na zakázce existují faktury.
 * Pro „soft" odstavení slouží archive endpoint.
 */
final class DeleteProjectAction
{
    public function __construct(
        private readonly ProjectRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE project_id = ?');
        $stmt->execute([$id]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            return Json::error(
                $response,
                'has_invoices',
                RequestAuthorization::allows($request, 'invoices')
                    ? "Zakázku nelze smazat — má {$count} navázaných faktur. Místo toho ji archivuj."
                    : 'Zakázku nelze smazat kvůli navázaným dokladům. Místo toho ji archivuj.',
                409,
            );
        }

        $this->db->pdo()->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('project.deleted', $user['id'] ?? null, 'project', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }
}
