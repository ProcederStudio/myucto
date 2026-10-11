<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SubmitsDocumentOriginals;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionDeletionService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionProcessingService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use MyInvoice\Service\PurchaseInvoice\SubmissionDimensions;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Účetní fronta příchozích originálů. */
final class PurchaseInvoiceSubmissionAction
{
    use SubmitsDocumentOriginals;

    private const STATUSES = ['submitted', 'processing', 'needs_information', 'processed', 'rejected'];

    public function __construct(
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly PurchaseInvoiceSubmissionProcessingService $processing,
        private readonly PurchaseInvoiceSubmissionUploadService $upload,
        private readonly DocumentRepository $documents,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SubmissionDimensions $submissionDimensions,
        private readonly PurchaseInvoiceSubmissionDeletionService $deletion,
    ) {}

    /**
     * Doklad, který přišel mimo portál — e-mailem, papírově, nebo si ho účetní
     * dává do fronty sama za sebe. Chová se stejně jako klientské podání: originál
     * jde do DMS a zůstane účetně neutrální, dokud ho někdo nezpracuje.
     */
    public function upload(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) return Json::error($response, 'no_supplier', 'Žádná firma není dostupná.', 403);

        $files = $this->uploadedOriginals($request);
        if ($files === []) return Json::error($response, 'no_file', 'Vyberte alespoň jeden soubor.', 400);
        if (count($files) > self::MAX_ORIGINALS_PER_BATCH) {
            return Json::error($response, 'too_many_files', 'Najednou lze nahrát nejvýše 20 souborů.', 413);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // Dimenze (typicky středisko) zvolené při nahrání se propíšou do hlavičky
        // vytěženého dokladu. Volit je smí, kdo smí dimenze dokladu i měnit.
        $dims = [];
        if (is_array($body['dimensions'] ?? null) && array_filter($body['dimensions']) !== []) {
            if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
                return Json::error($response, 'forbidden', 'Dimenze dokladu může zvolit jen uživatel s právem na účetnictví.', 403);
            }
            try {
                $dims = $this->submissionDimensions->validate($supplierId, $body['dimensions'], false);
            } catch (DimensionException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            }
        }
        $userId = $this->userId($request);
        $batch = $this->submitOriginals(
            $files,
            $supplierId,
            $userId > 0 ? $userId : null,
            'staff',
            isset($body['note']) ? (string) $body['note'] : null,
            isset($body['document_kind_hint']) ? (string) $body['document_kind_hint'] : null,
        );
        $items = $batch['items'];
        $errors = $batch['errors'];
        if ($items === [] && $batch['first_error'] instanceof PurchaseInvoiceSubmissionException) {
            $firstError = $batch['first_error'];
            return Json::error(
                $response,
                $firstError->errorCode,
                $firstError->getMessage(),
                $firstError->httpStatus,
                ['files' => $errors],
            );
        }

        foreach ($items as $item) {
            if ($dims !== [] && empty($item['duplicate'])) {
                $this->submissionDimensions->save($supplierId, (int) $item['submission']['id'], $dims);
            }
        }
        $items = $this->withDimensions($supplierId, $items);

        foreach ($items as $item) {
            $submission = $item['submission'];
            $this->audit(
                $request,
                !empty($item['duplicate'])
                    ? 'purchase_invoice_submission.duplicate'
                    : 'purchase_invoice_submission.created',
                (int) $submission['id'],
                [
                    'document_id' => (int) $submission['document_id'],
                    'filename' => (string) $submission['original_name'],
                    'via' => 'staff',
                ] + ($dims !== [] && empty($item['duplicate']) ? ['dimensions' => $dims] : []),
            );
        }

        $duplicates = $this->duplicateCount($items);
        return Json::ok($response, [
            'items' => array_map(static fn(array $item): array => $item['submission'] + [
                'duplicate' => (bool) $item['duplicate'],
            ], $items),
            'created' => count($items) - $duplicates,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ], $this->batchStatus($items, $errors, $duplicates));
    }

    public function list(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $q = $request->getQueryParams();
        $status = isset($q['status']) && in_array((string) $q['status'], self::STATUSES, true)
            ? (string) $q['status'] : null;
        $supplierId = SupplierGuard::currentId($request);
        $page = $this->submissions->paginate(
            $supplierId,
            $status,
            (int) ($q['limit'] ?? 50),
            (int) ($q['offset'] ?? 0),
        );
        $page['items'] = $this->submissionDimensions->attach($supplierId, $page['items']);
        return Json::ok($response, $page);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response)) return $denied;
        $item = $this->findWithDimensions((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        return $item !== null
            ? Json::ok($response, $item)
            : Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404);
    }

    /** @return array<string,mixed>|null */
    private function findWithDimensions(int $id, int $supplierId): ?array
    {
        $item = $this->submissions->find($id, $supplierId);
        if ($item === null) {
            return $item;
        }
        return $this->submissionDimensions->attach($supplierId, [$item])[0];
    }

    /**
     * @param list<array{submission:array<string,mixed>,duplicate:bool}> $items
     * @return list<array{submission:array<string,mixed>,duplicate:bool}>
     */
    private function withDimensions(int $supplierId, array $items): array
    {
        if ($items === []) {
            return $items;
        }
        $rows = $this->submissionDimensions->attach($supplierId, array_column($items, 'submission'));
        foreach ($items as $i => $item) {
            $items[$i]['submission'] = $rows[$i];
        }
        return $items;
    }

    public function extract(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        if (!RequestAuthorization::allows($request, 'purchase_invoices.create', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění vytvořit přijatou fakturu.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $userId = $this->userId($request);
        try {
            $result = $this->processing->extract(
                $id,
                $supplierId,
                $userId,
                RequestAuthorization::allows($request, 'purchase_invoices.scan', AccessLevel::WRITE),
            );
        } catch (PurchaseInvoiceSubmissionException $e) {
            $this->audit($request, 'purchase_invoice_submission.extraction_failed', $id, [
                'code' => $e->errorCode,
                'error' => $e->getMessage(),
            ]);
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->audit($request, 'purchase_invoice_submission.processed', $id, $result);
        return Json::ok($response, (array) $this->findWithDimensions($id, $supplierId) + [
            'duplicate' => (bool) $result['duplicate'],
        ]);
    }

    /**
     * Hromadné doplnění dimenzí vybraným podáním ve frontě. Zvolené typy přepíše,
     * ostatní dimenze podání nechá. Zpracované podání už má fakturu, na kterou se
     * dimenze propsaly, proto se přeskočí.
     */
    public function bulkDimensions(Request $request, Response $response): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        if (!RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Dimenze dokladu může zvolit jen uživatel s právem na účetnictví.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($body['ids'] ?? null) ? $body['ids'] : []),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) return Json::error($response, 'no_items', 'Vyberte alespoň jeden doklad.', 400);
        if (count($ids) > 500) return Json::error($response, 'too_many_items', 'Najednou lze upravit nejvýše 500 dokladů.', 413);
        try {
            $dims = $this->submissionDimensions->validate(
                $supplierId,
                is_array($body['dimensions'] ?? null) ? $body['dimensions'] : [],
                false,
            );
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if ($dims === []) return Json::error($response, 'no_dimensions', 'Zvolte alespoň jednu hodnotu dimenze.', 400);

        $existing = $this->submissionDimensions->forSubmissions($supplierId, $ids);
        $updated = [];
        $skipped = [];
        foreach ($ids as $id) {
            $submission = $this->submissions->find($id, $supplierId);
            if ($submission === null || !in_array((string) $submission['status'], ['submitted', 'needs_information'], true)) {
                $skipped[] = $id;
                continue;
            }
            $merged = array_replace($existing[$id] ?? [], $dims);
            $this->submissionDimensions->save($supplierId, $id, $merged);
            $this->audit($request, 'purchase_invoice_submission.dimensions_changed', $id, ['dimensions' => $merged]);
            $updated[] = $id;
        }
        return Json::ok($response, ['updated' => $updated, 'skipped' => $skipped]);
    }

    public function needsInformation(Request $request, Response $response, array $args): Response
    {
        return $this->review($request, $response, $args, 'needs_information');
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        return $this->review($request, $response, $args, 'rejected');
    }

    /**
     * Vyřadí podání z fronty i s originálem.
     *
     * Odmítnutí je jen stav — originál zůstane dohledatelný, protože u dokladu od
     * klienta je součástí auditní stopy. Tím ale ve frontě zůstává i to, co tam
     * nemá co dělat (omylem nahraná fotka, spam). Trvalý úklid je proto samostatné
     * právo `documents.inbox.delete`: podání zmizí a jeho originál se přesune do koše
     * Dokumentů, odkud ho vysypání koše smaže i z disku. Zpracované podání se
     * nemaže — drží vazbu na existující fakturu.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        if (!RequestAuthorization::allows($request, 'documents.inbox.delete', AccessLevel::WRITE)) {
            return Json::error(
                $response,
                'forbidden',
                'K trvalému vyřazení dokladu z fronty nemáte oprávnění.',
                403,
            );
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $userId = $this->userId($request);

        try {
            return Json::ok($response, $this->deletion->delete(
                $id, $supplierId, $userId, null, false,
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            ));
        } catch (PurchaseInvoiceSubmissionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function review(Request $request, Response $response, array $args, string $status): Response
    {
        if ($denied = $this->deny($request, $response, true)) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($this->submissions->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404);
        }
        if ($reason === '') {
            return Json::error($response, 'reason_required', 'Doplňte důvod.', 400);
        }
        $changed = $status === 'needs_information'
            ? $this->submissions->needsInformation($id, $supplierId, $reason)
            : $this->submissions->reject($id, $supplierId, $reason);
        if (!$changed) return Json::error($response, 'invalid_status', 'Stav podání se mezitím změnil.', 409);
        $this->audit($request, 'purchase_invoice_submission.' . $status, $id, ['reason' => $reason]);
        return Json::ok($response, $this->findWithDimensions($id, $supplierId));
    }

    private function deny(Request $request, Response $response, bool $write = false): ?Response
    {
        $level = $write ? AccessLevel::WRITE : AccessLevel::READ;
        if (RequestAuthorization::isClientType($request)
            || !RequestAuthorization::allows($request, 'documents.inbox', $level)) {
            return Json::error($response, 'forbidden', 'K příchozím dokladům nemáte oprávnění.', 403);
        }
        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return max(0, (int) ($user['id'] ?? 0));
    }

    /** @param array<string,mixed> $context */
    private function audit(Request $request, string $event, int $id, array $context): void
    {
        $this->logger->log(
            $event,
            $this->userId($request) ?: null,
            'purchase_invoice_submission',
            $id,
            $context,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            SupplierGuard::currentId($request),
        );
    }
}
