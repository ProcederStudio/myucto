<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\OdometerReadingRepository;
use MyInvoice\Repository\TripTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\OdometerGapCalculator;
use MyInvoice\Service\Logbook\OdometerGapFiller;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Km k doplnění podle stavů tachometru:
 *   GET  /api/logbook/odometer-gaps        (?car_id=)
 *   POST /api/logbook/odometer-gaps/fill   — {reading_id, trips:[…], remainder_private, remainder_purpose}
 *
 * Řádek jízdy nese buď template_id (hodnoty se vezmou z pravidelné cesty a řádek je
 * může přepsat), nebo vlastní odkud/kam/účel/kategorii/km.
 */
final class OdometerGapsAction
{
    private const TRIP_FIELDS = ['trip_date', 'time_start', 'time_end', 'distance_km', 'category_id',
        'purpose', 'origin', 'destination'];

    public function __construct(
        private readonly OdometerGapCalculator $calculator,
        private readonly OdometerGapFiller $filler,
        private readonly OdometerReadingRepository $readings,
        private readonly TripTemplateRepository $templates,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TenantReferenceGuard $tenantRefs,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $carId = (int) ($request->getQueryParams()['car_id'] ?? 0) ?: null;
        return Json::ok($response, $this->calculator->forTenant($supplierId, $carId));
    }

    public function fill(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $reading = $this->readings->find((int) ($body['reading_id'] ?? 0), $supplierId);
        if ($reading === null) {
            return Json::error($response, 'not_found', 'Stav tachometru nenalezen.', 404);
        }
        $gap = $this->calculator->forReading($supplierId, $reading['car_id'], $reading['id']);
        if ($gap === null) {
            return Json::error($response, 'no_gap', 'K tomuto stavu tachometru už nejsou žádné km k doplnění.', 409);
        }

        $rows = [];
        $sum = 0.0;
        foreach ((array) ($body['trips'] ?? []) as $i => $input) {
            $row = $this->resolveRow($supplierId, (array) $input, $gap);
            if (is_string($row)) {
                return Json::error($response, 'validation_failed', 'Jízda ' . ((int) $i + 1) . ': ' . $row, 400);
            }
            if ($ref = $this->tenantRefError($supplierId, $row)) {
                return Json::error($response, 'invalid_reference', $ref, 400);
            }
            $sum += $row['distance_km'];
            $rows[] = $row;
        }
        if ($sum > $gap['km'] + 0.05) {
            return Json::error($response, 'exceeds_gap',
                sprintf('Jízdy mají dohromady %s km, k doplnění je jen %d km.', rtrim(rtrim(number_format($sum, 1, '.', ''), '0'), '.'), $gap['km']), 422);
        }

        $remainderPurpose = null;
        if (!empty($body['remainder_private'])) {
            if ($this->filler->privateCategoryId($supplierId) === null) {
                return Json::error($response, 'no_private_category',
                    'Chybí kategorie cest označená jako soukromá jízda.', 400);
            }
            $remainderPurpose = trim((string) ($body['remainder_purpose'] ?? '')) ?: 'Soukromá jízda';
        }
        if ($rows === [] && $remainderPurpose === null) {
            return Json::error($response, 'validation_failed', 'Přidejte aspoň jednu jízdu.', 400);
        }

        $ids = $this->filler->fill($supplierId, $gap, $rows, $remainderPurpose, $this->userId($request));
        $this->log($request, 'odometer_gap.filled', $reading['id'], ['gap' => $gap, 'trip_ids' => $ids]);
        return Json::ok($response, ['trip_ids' => $ids, 'gap' => $gap], 201);
    }

    /** @return array<string,mixed>|string řádek jízdy, nebo chybová hláška */
    private function resolveRow(int $supplierId, array $input, array $gap): array|string
    {
        $row = [];
        $templateId = (int) ($input['template_id'] ?? 0);
        if ($templateId > 0) {
            $template = $this->templates->find($templateId, $supplierId);
            if ($template === null) return 'Pravidelná cesta neexistuje.';
            if ($template['car_id'] !== null && $template['car_id'] !== (int) $gap['car_id']) {
                return 'Pravidelná cesta patří jinému autu.';
            }
            $row = array_intersect_key($template, array_flip(self::TRIP_FIELDS));
        }
        foreach (self::TRIP_FIELDS as $field) {
            if (array_key_exists($field, $input)) $row[$field] = $input[$field];
        }

        $date = trim((string) ($row['trip_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Neplatné datum (formát YYYY-MM-DD).';
        if ($date > $gap['date_to'] || ($gap['date_from'] !== null && $date < $gap['date_from'])) {
            return 'Datum musí ležet v období km k doplnění.';
        }
        $distance = round((float) ($row['distance_km'] ?? 0), 1);
        if ($distance <= 0) return 'Ujeté km musí být kladné číslo.';
        foreach (['time_start', 'time_end'] as $key) {
            $time = trim((string) ($row[$key] ?? ''));
            if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
                return 'Neplatný čas (formát HH:MM).';
            }
        }
        $row['trip_date'] = $date;
        $row['distance_km'] = $distance;
        return $row;
    }

    private function tenantRefError(int $supplierId, array $row): ?string
    {
        $badRefs = $this->tenantRefs->violations($supplierId, $row, ['category_id']);

        return $badRefs !== [] ? TenantReferenceGuard::message($badRefs) : null;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'odometer_reading', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
