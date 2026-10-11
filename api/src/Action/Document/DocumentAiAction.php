<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Service\Ai\AccountingAssistantException;
use MyInvoice\Service\Ai\AccountingAssistantService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** AI nad dokumentem: shrnutí (uloží se a je fulltextově prohledávatelné) a vytěžení dokladu. */
final class DocumentAiAction
{
    use DocumentActionTrait;

    public function __construct(private readonly AccountingAssistantService $assistant) {}

    /** POST /api/documents/{id}/ai/summary {length: short|long} */
    public function summary(Request $request, Response $response, array $args): Response
    {
        @set_time_limit(300);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->assistant->summarizeDocument(
                $this->supplierId($request),
                $this->viewer($request),
                (int) ($args['id'] ?? 0),
                is_string($body['length'] ?? null) ? $body['length'] : 'short',
            );
        } catch (AccountingAssistantException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, $result);
    }

    /** POST /api/documents/{id}/ai/analysis {detail: short|long, accounting: bool} */
    public function analysis(Request $request, Response $response, array $args): Response
    {
        @set_time_limit(300);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $analysis = $this->assistant->analyzeDocument(
                $this->supplierId($request),
                $this->viewer($request),
                (int) ($args['id'] ?? 0),
                is_string($body['detail'] ?? null) ? $body['detail'] : 'short',
                in_array($body['accounting'] ?? false, [true, 1, '1', 'true'], true),
            );
        } catch (AccountingAssistantException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, ['analysis' => $analysis]);
    }
}
