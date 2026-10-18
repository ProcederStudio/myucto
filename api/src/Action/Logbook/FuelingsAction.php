<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\ReferenceId;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\FuelingLinkCandidates;
use MyInvoice\Service\Logbook\FuelingOdometerEstimator;
use MyInvoice\Service\Logbook\FuelingOdometerFill;
use MyInvoice\Service\Logbook\FuelingOdometerWarnings;
use MyInvoice\Service\Logbook\VehicleResolver;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tankování:
 *   GET    /api/logbook/fuelings      — list (?car_id=&source=&vendor_id=&year=&month=&date_from=&date_to=&unassigned=1&page=&per_page=)
 *   GET    /api/logbook/fuelings/{id}
 *   POST   /api/logbook/fuelings      — ruční záznam
 *   PUT    /api/logbook/fuelings/{id}
 *   DELETE /api/logbook/fuelings/{id}
 *   GET    /api/logbook/fuelings/warnings        — chybějící / nesouvislý tachometr, nesoulad odpočtu DPH
 *   POST   /api/logbook/fuelings/estimate-odometers - doplní odhad tachometru tam, kde chybí ({car_id?, year?, dry_run?})
 *   GET    /api/logbook/fuelings/link-candidates — doklady k navázání (?type=&date=&amount=&q=)
 */
final class FuelingsAction
{
    /** Vazby na doklad, kterým bylo tankování zaplaceno (migrace 1801). */
    private const LINK_COLUMNS = ['source_purchase_invoice_id', 'source_cash_document_id', 'source_bank_transaction_id', 'source_journal_entry_id'];

    /** Sloupce upravitelné přes PUT; vynechané se berou z uloženého záznamu. */
    private const UPDATABLE = ['car_id', 'fueled_date', 'fueled_time', 'fuel_type', 'quantity', 'unit', 'unit_price',
        'amount_without_vat', 'amount_vat', 'amount_with_vat', 'currency', 'odometer', 'station', 'vendor_id',
        'receipt_number', 'note'];

    public function __construct(
        private readonly FuelingRepository $repo,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly FuelingOdometerEstimator $odometer,
        private readonly TenantReferenceGuard $tenantRefs,
        private readonly \MyInvoice\Service\Stock\StockReferenceGuard $stockRefs,
        private readonly FuelingOdometerWarnings $warnings,
        private readonly FuelingLinkCandidates $linkCandidates,
        private readonly VehicleResolver $vehicles,
        private readonly FuelingOdometerFill $odometerFill,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $filters = array_intersect_key($q, array_flip(['car_id', 'source', 'vendor_id', 'year', 'month', 'date_from', 'date_to', 'unassigned']));
        $p = Pagination::fromQuery($q, 50);
        [$rows, $total] = $this->repo->listPaged($supplierId, $filters, $p['per_page'], $p['offset']);
        $rows = $this->odometer->annotate($supplierId, $rows);
        $rows = $this->warnings->annotate($supplierId, $rows);
        $rows = array_map(static fn (array $row): array => FuelingSourceAccess::redact($request, $row), $rows);
        $carId = !empty($filters['car_id']) ? (int) $filters['car_id'] : null;
        $years = $this->repo->distinctYears($supplierId, $carId);
        return Json::ok($response, array_merge(
            Pagination::envelope($rows, $total, $p['page'], $p['per_page']),
            ['years' => $years]
        ));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $f = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($f === null) return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        $rows = $this->warnings->annotate($supplierId, $this->odometer->annotate($supplierId, [$f]));
        return Json::ok($response, FuelingSourceAccess::redact($request, $rows[0]));
    }

    public function warnings(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $carId = !empty($q['car_id']) ? (int) $q['car_id'] : null;
        $year = !empty($q['year']) ? (int) $q['year'] : null;
        $result = $this->warnings->forTenant($supplierId, $carId, $year);

        if (!RequestAuthorization::allows($request, 'purchase_invoices') || !RequestAuthorization::allows($request, 'cash')) {
            foreach ($result['cars'] as &$car) $car['vat_mismatches'] = [];
            unset($car);
            $result['totals']['vat_mismatches'] = 0;
        }

        // Kolik chybějících stavů jde doplnit odhadem, a u vozu, kde nejde, proč.
        $byCar = [];
        $result['totals']['estimable'] = 0;
        if ($result['totals']['missing'] > 0) {
            $preview = $this->odometerFill->preview($supplierId, $carId, $year);
            foreach ($preview['cars'] as $c) $byCar[$c['car_id']] = $c;
            $result['totals']['estimable'] = $preview['totals']['estimable'];
        }
        foreach ($result['cars'] as &$c) {
            $p = $byCar[$c['car_id']] ?? null;
            $c['odometer_estimate'] = ['estimable' => $p['estimable'] ?? 0, 'reason' => $p['reason'] ?? null];
        }
        unset($c);
        return Json::ok($response, $result);
    }

    /**
     * Doplní odhad tachometru tankováním bez stavu (vybrané vozidlo / rok, jinak všechna).
     * Zadané stavy nikdy nepřepíše; `dry_run` jen spočítá, co by se doplnilo.
     */
    public function estimateOdometers(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $carId = $this->intOrNull($body['car_id'] ?? null);
        if ($carId !== null && $this->cars->find($carId, $supplierId) === null) {
            return Json::error($response, 'validation_failed', 'Auto neexistuje.', 400);
        }
        $year = $this->intOrNull($body['year'] ?? null);
        if ($year !== null && ($year < 1900 || $year > 2999)) {
            return Json::error($response, 'validation_failed', 'Neplatný rok.', 400);
        }
        if (!empty($body['dry_run'])) {
            return Json::ok($response, ['filled' => 0, 'filled_ids' => []] + $this->odometerFill->preview($supplierId, $carId, $year));
        }

        $result = $this->odometerFill->apply($supplierId, $carId, $year);
        if ($result['filled'] > 0) {
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('fueling.odometer_estimated', $this->userId($request), 'fueling', null, [
                'car_id'    => $carId,
                'year'      => $year,
                'filled'    => $result['filled'],
                'estimates' => $result['estimates'],
            ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);
        }
        unset($result['estimates']);
        return Json::ok($response, $result);
    }

    public function linkCandidates(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $permission = FuelingSourceAccess::candidatePermission((string) ($q['type'] ?? ''));
        if ($permission !== null && !RequestAuthorization::allows($request, $permission)) {
            return Json::error($response, 'forbidden_permission', 'Chybí oprávnění číst zdrojovou evidenci.', 403);
        }
        $amount = isset($q['amount']) && is_numeric($q['amount']) ? (float) $q['amount'] : null;
        return Json::ok($response, $this->linkCandidates->find(
            $supplierId,
            (string) ($q['type'] ?? ''),
            (string) ($q['date'] ?? ''),
            $amount,
            (string) ($q['q'] ?? ''),
        ));
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if (FuelingSourceAccess::forbiddenChange($request, $body)) {
            return Json::error($response, 'forbidden_permission', 'Chybí oprávnění číst zdrojovou evidenci.', 403);
        }
        $err = $this->validate($supplierId, $body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }
        $this->adoptBankStatement($supplierId, $body);
        $body['source'] = 'manual';
        $id = $this->repo->create($supplierId, $body, $this->userId($request));
        $this->assignVehicle($supplierId, $id, $body, null);
        $this->log($request, 'fueling.created', $id, $body);
        return Json::ok($response, FuelingSourceAccess::redact($request, $this->repo->find($id, $supplierId) ?? []), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $before = $this->repo->find($id, $supplierId);
        if ($before === null) {
            return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (FuelingSourceAccess::forbiddenChange($request, $body, $before)) {
            return Json::error($response, 'forbidden_permission', 'Chybí oprávnění číst zdrojovou evidenci.', 403);
        }
        // Vynechaný klíč = ponechat uloženou hodnotu; poslaný null / "" = vymazat.
        $merged = $body + array_intersect_key($before, array_flip(self::UPDATABLE));
        $this->rescaleVatSplit($body, $before, $merged);
        $err = $this->validate($supplierId, $merged);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }
        $this->adoptBankStatement($supplierId, $body);
        $this->repo->update($id, $supplierId, $merged);
        // Vazby na doklad mění jen klíče, které klient poslal — ruční úprava údajů
        // tankování (bez vazeb v těle) provenienci nepřepisuje.
        $this->repo->setLinks($id, $supplierId, array_intersect_key($body, array_flip(self::LINK_COLUMNS)));
        $this->assignVehicle($supplierId, $id, $merged + $body, $before);
        $this->log($request, 'fueling.updated', $id, $body);
        return Json::ok($response, FuelingSourceAccess::redact($request, $this->repo->find($id, $supplierId) ?? []));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Tankování nenalezeno.', 404);
        }
        $this->repo->delete($id, $supplierId);
        $this->log($request, 'fueling.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Změní-li se celková částka a klient rozpis DPH neposlal, uložený rozpis by byl
     * nekonzistentní s novým součtem - přepočte se poměrem původního rozpisu.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $before
     * @param array<string,mixed> $merged
     */
    private function rescaleVatSplit(array $body, array $before, array &$merged): void
    {
        if (array_key_exists('amount_without_vat', $body) || array_key_exists('amount_vat', $body)
            || !array_key_exists('amount_with_vat', $body) || !is_numeric($body['amount_with_vat'])) {
            return;
        }
        $old = (float) ($before['amount_with_vat'] ?? 0);
        $new = (float) $body['amount_with_vat'];
        if ($old <= 0 || $new <= 0 || abs($new - $old) < 0.005
            || $before['amount_without_vat'] === null || $before['amount_vat'] === null) {
            return;
        }
        $without = round($new * (float) $before['amount_without_vat'] / $old, 2);
        $merged['amount_without_vat'] = $without;
        $merged['amount_vat'] = round($new - $without, 2);
    }

    private function validate(int $supplierId, array $body): ?string
    {
        $date = trim((string) ($body['fueled_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Neplatné datum tankování (YYYY-MM-DD).';
        if (!isset($body['amount_with_vat']) || (float) $body['amount_with_vat'] <= 0) {
            return 'Celková částka musí být kladná.';
        }
        $carId = $this->intOrNull($body['car_id'] ?? null);
        if ($carId !== null && $this->cars->find($carId, $supplierId) === null) {
            return 'Auto neexistuje.';
        }
        return null;
    }

    /**
     * BOLA guard (security report 2026-08, R2 #6 / sweep F1) — car_id se ve validate()
     * kontroluje odjakživa, vendor_id a source_purchase_invoice_id se zapisovaly
     * nevázané a FuelingRepository::find() je čte přes JOIN bez tenant predikátu.
     *
     * Vazby na pokladní doklad a účetní zápis jdou guardem; bankovní pohyb `supplier_id`
     * nemá, jeho vlastníka určuje výpis (FuelingRepository::bankTransactionBelongs()).
     * Obojí navíc drží trigger z migrace 1801.
     */
    private function tenantRefError(int $supplierId, array $body): ?string
    {
        $badRefs = $this->tenantRefs->violations(
            $supplierId,
            $body,
            ['vendor_id', 'source_purchase_invoice_id', 'source_cash_document_id', 'source_journal_entry_id'],
        );

        $itemRefs = $this->stockRefs->violations(
            $supplierId, ['purchase_invoice_item_id' => [$body['source_item_id'] ?? null]],
        );
        if ($itemRefs !== []) {
            $badRefs[] = 'source_item_id';
        }

        try {
            $bankTx = ReferenceId::optional($body['source_bank_transaction_id'] ?? null);
            if ($bankTx !== null && !$this->repo->bankTransactionBelongs($supplierId, $bankTx)) {
                $badRefs[] = 'source_bank_transaction_id';
            }
        } catch (\InvalidArgumentException) {
            $badRefs[] = 'source_bank_transaction_id';
        }
        return $badRefs !== [] ? TenantReferenceGuard::message($badRefs) : null;
    }

    /**
     * Pohyb z legacy výpisu, který tenantRefError() pustil přes resolver, musí mít
     * vlastníka zapsaného dřív, než vazbu uloží — jinak ji odmítne trigger 1801
     * (viz FuelingRepository::adoptLegacyStatement()).
     *
     * @param array<string,mixed> $body
     */
    private function adoptBankStatement(int $supplierId, array $body): void
    {
        $bankTx = $this->intOrNull($body['source_bank_transaction_id'] ?? null);
        if ($bankTx !== null) {
            $this->repo->adoptLegacyStatement($supplierId, $bankTx);
        }
    }

    /**
     * Způsob přiřazení vozidla po ruční úpravě. Vybrané vozidlo = „explicit". Bez vozidla
     * a s NOVĚ navázaným bankovním pohybem kartou se vozidlo dohledá přes kartu
     * (karta → držitel → jeho vozidlo); výchozí vozidlo se u ručního záznamu nedosazuje.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $before stav před úpravou (null = nový záznam)
     */
    private function assignVehicle(int $supplierId, int $id, array $body, ?array $before): void
    {
        $carId = $this->intOrNull($body['car_id'] ?? null);
        $bankTx = array_key_exists('source_bank_transaction_id', $body)
            ? $this->intOrNull($body['source_bank_transaction_id'])
            : ($before['source_bank_transaction_id'] ?? null);
        $bankChanged = $bankTx !== null && $bankTx !== ($before['source_bank_transaction_id'] ?? null);
        $card = $bankTx !== null ? $this->repo->bankTransactionCard($supplierId, (int) $bankTx) : null;
        $last4 = $card['card_last4'] ?? null;

        if ($carId !== null) {
            if ($before === null || $carId !== ($before['car_id'] ?? null)) {
                $this->repo->setCarAssignment($id, $supplierId, $carId, 'explicit', $last4);
            } elseif ($last4 !== null) {
                $this->repo->setCarAssignment($id, $supplierId, $carId, $before['car_assigned_by'] ?? null, $last4);
            }
            return;
        }
        if ($card !== null && $last4 !== null && ($before === null || $bankChanged)) {
            $v = $this->vehicles->resolve($supplierId, ['card_last4' => $last4, 'date' => $card['posted_at']], false);
            $this->repo->setCarAssignment($id, $supplierId, $v['car_id'], $v['method'], $last4);
            return;
        }
        if ($before !== null && ($before['car_id'] ?? null) !== null) {
            $this->repo->setCarAssignment($id, $supplierId, null, null);
        }
    }

    private function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'fueling', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
