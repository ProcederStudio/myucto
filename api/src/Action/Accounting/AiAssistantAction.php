<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Ai\AccountingAssistantException;
use MyInvoice\Service\Ai\AccountingAssistantService;
use MyInvoice\Service\Document\DocumentViewerResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/** Účetnictví → AI asistent: stav dostupnosti a dotaz s přílohami. */
final class AiAssistantAction
{
    public function __construct(private readonly AccountingAssistantService $assistant) {}

    /** GET /api/accounting/ai-assistant/status */
    public function status(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->assistant->status(SupplierGuard::currentId($request)));
    }

    /** POST /api/accounting/ai-assistant/ask (multipart/form-data) */
    public function ask(Request $request, Response $response): Response
    {
        @set_time_limit(300);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $history = [];
            $rawHistory = $body['history'] ?? '';
            if (is_string($rawHistory) && trim($rawHistory) !== '') {
                $history = json_decode($rawHistory, true);
                if (!is_array($history) || !array_is_list($history)) {
                    throw AccountingAssistantException::invalid('Historie konverzace není platný JSON seznam.');
                }
            } elseif (is_array($rawHistory)) {
                $history = array_values($rawHistory);
            }
            $documentIds = $body['document_ids'] ?? [];
            $documentIds = is_array($documentIds) ? array_values($documentIds) : [$documentIds];
            $documentIds = array_values(array_filter($documentIds, static fn ($v): bool => $v !== '' && $v !== null));

            $result = $this->assistant->ask(
                SupplierGuard::currentId($request),
                DocumentViewerResolver::fromRequest($request),
                is_string($body['question'] ?? null) ? $body['question'] : '',
                $history,
                $this->uploadedFiles($request),
                $documentIds,
                in_array($body['include_chart'] ?? '0', ['1', 1, true, 'true'], true),
            );
        } catch (AccountingAssistantException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, $result);
    }

    /** @return list<array{name:string, bytes:string}> */
    private function uploadedFiles(Request $request): array
    {
        $uploaded = $request->getUploadedFiles()['files'] ?? [];
        if ($uploaded instanceof UploadedFileInterface) {
            $uploaded = [$uploaded];
        }
        if (!is_array($uploaded)) {
            return [];
        }
        if (count($uploaded) > AccountingAssistantService::MAX_FILES) {
            throw AccountingAssistantException::invalid('Přiložit lze nejvýše 5 souborů.');
        }
        $out = [];
        foreach ($uploaded as $file) {
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }
            $name = (string) ($file->getClientFilename() ?? 'soubor');
            if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
                throw AccountingAssistantException::invalid('Soubor ' . $name . ' překračuje povolenou velikost.');
            }
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw AccountingAssistantException::invalid('Soubor ' . $name . ' se nepodařilo nahrát.');
            }
            if ((int) $file->getSize() > AccountingAssistantService::MAX_FILE_BYTES) {
                throw AccountingAssistantException::invalid('Soubor ' . $name . ' je větší než 15 MB.');
            }
            $out[] = ['name' => $name, 'bytes' => (string) $file->getStream()];
        }
        return $out;
    }
}
