<?php

declare(strict_types=1);

namespace MyInvoice\Action\Portal;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Epic F6 — klientský portál, chybějící doklady (Fáze F, audit 2026-07):
 *
 *   GET  /api/portal/document-requests               — vlastní požadavky (aktivní firma)
 *   POST /api/portal/document-requests/{id}/upload    — nahrání dokladu, field "file"
 *
 * Upload ukládá originál do samostatné staging fronty; přijatá faktura vznikne až
 * při kontrole účetní. Supplier scope řeší SupplierScopeMiddleware
 * (klient bez membershipu = fail-closed 403 v resolveru) — KRITICKÉ pro tenant izolaci,
 * klient nesmí vidět ani ovlivnit požadavky jiné firmy.
 */
final class PortalDocumentRequestAction
{
    public function __construct(
        private readonly DocumentRequestRepository $repo,
        private readonly PurchaseInvoiceSubmissionUploadService $upload,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allowsClientDocumentPortal($request, AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'K požadavkům na doklady nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);
        }
        return Json::ok($response, ['items' => $this->repo->listForSupplier($supplierId)]);
    }

    public function upload(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allowsClientDocumentPortal($request, AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'K předávání dokladů nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $item = $this->repo->find($id, $supplierId);
        if ($item === null) {
            return Json::error($response, 'not_found', 'Požadavek nenalezen.', 404);
        }
        if ((string) $item['status'] !== 'requested') {
            return Json::error($response, 'already_uploaded', 'K požadavku už byl doklad předán.', 409);
        }

        $uploads = $request->getUploadedFiles();
        $file = $uploads['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte soubor v poli "file".', 400);
        }
        $userId = $this->userId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->upload->submit(
                $file,
                $supplierId,
                $userId,
                'document_request',
                isset($body['note']) && trim((string) $body['note']) !== ''
                    ? (string) $body['note']
                    : (string) $item['description'],
                isset($body['document_kind_hint']) ? (string) $body['document_kind_hint'] : null,
                isset($item['bank_transaction_id']) ? (int) $item['bank_transaction_id'] : null,
            );
        } catch (PurchaseInvoiceSubmissionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $submission = $result['submission'];
        $submissionId = (int) $submission['id'];
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            !empty($result['duplicate']) ? 'purchase_invoice_submission.duplicate' : 'purchase_invoice_submission.created',
            $userId,
            'purchase_invoice_submission',
            $submissionId,
            [
                'document_id' => (int) $submission['document_id'],
                'filename' => (string) $submission['original_name'],
                'via' => 'document_request',
                'document_request_id' => $id,
            ],
            $ip,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        if (!$this->repo->markSubmitted($id, $supplierId, $submissionId)) {
            return Json::error($response, 'request_state_changed', 'Požadavek se mezitím změnil.', 409);
        }
        if ((string) ($submission['status'] ?? '') === 'processed'
            && (int) ($submission['purchase_invoice_id'] ?? 0) > 0) {
            $this->repo->markProcessedBySubmission(
                $submissionId,
                $supplierId,
                (int) $submission['purchase_invoice_id'],
            );
        }

        $this->logger->log('document_request.uploaded', $userId, 'document_request', $id, [
            'submission_id' => $submissionId,
            'document_id' => (int) $submission['document_id'],
            'duplicate' => (bool) $result['duplicate'],
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}
