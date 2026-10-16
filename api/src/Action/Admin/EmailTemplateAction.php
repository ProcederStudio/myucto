<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\EmailTemplateCatalog;
use MyInvoice\Service\Mail\EmailTemplateResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin CRUD pro email šablony:
 *   GET    /api/admin/email-templates                   — list (DB + file defaults)
 *   GET    /api/admin/email-templates/{code}/{locale}   — detail (DB nebo defaultní soubor)
 *   PUT    /api/admin/email-templates/{code}/{locale}   — upsert override
 *   DELETE /api/admin/email-templates/{code}/{locale}   — smaž override (vrátí na default)
 */
final class EmailTemplateAction
{
    public function __construct(
        private readonly EmailTemplateRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Mailer $mailer,
        private readonly EmailTemplateCatalog $catalog,
        private readonly EmailTemplateResolver $resolver,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;

        $byKey = [];
        foreach ($this->repo->listAll() as $row) {
            $byKey[$row['code'] . '.' . $row['locale']] = [
                'has_override' => true,
                'updated_at'   => $row['updated_at'],
            ];
        }

        $rows = [];
        foreach (EmailTemplateCatalog::CODES as $code) {
            foreach (EmailTemplateCatalog::LOCALES as $locale) {
                $key = "$code.$locale";
                $rows[] = [
                    'code'         => $code,
                    'locale'       => $locale,
                    'has_override' => $byKey[$key]['has_override'] ?? false,
                    'updated_at'   => $byKey[$key]['updated_at'] ?? null,
                ];
            }
        }
        return Json::ok($response, ['data' => $rows]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        if (!in_array($code, EmailTemplateCatalog::CODES, true) || !in_array($locale, EmailTemplateCatalog::LOCALES, true)) {
            return Json::error($response, 'not_found', 'Šablona neexistuje.', 404);
        }

        $tpl = $this->repo->find($code, $locale);
        $defaults = $this->catalog->defaults($code, $locale) ?? $this->catalog->defaults($code, 'cs');
        $resolved = $this->resolver->resolve($code, $locale);

        return Json::ok($response, [
            'code'      => $code,
            'locale'    => $locale,
            'subject'   => $resolved['subject'],
            'body_html' => $resolved['body_html'],
            'body_text' => $resolved['body_text'],
            'has_override' => $tpl !== null,
            'updated_at'   => $tpl['updated_at'] ?? null,
            'defaults'  => $defaults, // pro „Reset na default" tlačítko v UI
        ]);
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        if (!in_array($code, EmailTemplateCatalog::CODES, true) || !in_array($locale, EmailTemplateCatalog::LOCALES, true)) {
            return Json::error($response, 'not_found', 'Šablona neexistuje.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $subject  = trim((string) ($body['subject']   ?? ''));
        $bodyHtml = (string) ($body['body_html'] ?? '');
        $bodyText = (string) ($body['body_text'] ?? '');

        if ($subject === '')  return Json::error($response, 'validation_failed', 'Chybí subject.', 400);
        if ($bodyHtml === '') return Json::error($response, 'validation_failed', 'Chybí body_html.', 400);
        if ($bodyText === '') return Json::error($response, 'validation_failed', 'Chybí body_text.', 400);

        // Pre-render přes sandbox — zachytíme nepovolené tagy/filtry/syntax dřív,
        // než user pošle email a uvidí runtime crash (issue #25 follow-up).
        $validation = $this->mailer->validateUserTemplate($bodyHtml, $bodyText, $subject);
        if ($validation !== null) {
            return Json::error($response, 'validation_failed', $validation['message'], 400, [
                'field' => $validation['field'],
            ]);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->repo->save($code, $locale, $subject, $bodyHtml, $bodyText, isset($user['id']) ? (int) $user['id'] : null);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email_template.saved', $user['id'] ?? null, 'email_template', null, [
            'code' => $code, 'locale' => $locale,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['saved' => true]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $code = (string) ($args['code'] ?? '');
        $locale = (string) ($args['locale'] ?? '');
        $this->repo->delete($code, $locale);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('email_template.reset', $user['id'] ?? null, 'email_template', null, [
            'code' => $code, 'locale' => $locale,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    private function guard(Request $request, Response $response, ?Response &$err): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::isSuperadmin($request)) {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }
}
