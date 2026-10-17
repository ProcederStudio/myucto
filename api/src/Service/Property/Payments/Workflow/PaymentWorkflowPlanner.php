<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\PropertyInput;

final class PaymentWorkflowPlanner
{
    public function __construct(private readonly Connection $db, private readonly PaymentSourceEligibility $eligibility, private readonly PaymentRepository $payments) {}

    public function current(int $supplier, array $request): array
    {
        PropertyInput::fields($request, ['source', 'clearing_cells', 'parts']);
        if (!is_array($request['source'] ?? null) || !is_array($request['clearing_cells'] ?? null)) PropertyInput::invalid('source');
        $source = PaymentSourcePreview::capture($this->eligibility, $supplier, $request['source'], $request['clearing_cells']);
        $facts = $source->facts;
        $sourceState = $source->source($supplier);
        $nativeLines = [['account_code' => $facts['money_account'], 'side' => $facts['direction'] === 'in' ? 'debit' : 'credit',
            'amount' => bcdiv($facts['amount_cents'], '100', 2)]];
        foreach ($sourceState['receipt']['clearing_cells'] ?? [] as $cell) $nativeLines[] = $cell['line'];
        $journal = new PaymentWorkflowJournalPlan($this->db);
        $native = $facts['existing'] === null ? $journal->current($supplier, $facts['date'], $facts['kind'], $nativeLines) : null;
        $parts = PropertyInput::list($request['parts'] ?? null, 'parts');
        if ($parts === []) PropertyInput::invalid('parts');
        $plans = []; $keys = []; $total = '0';
        $capacity = new PaymentWorkflowCapacity($sourceState);
        foreach ($parts as $part) {
            if (!is_array($part)) PropertyInput::invalid('parts');
            PropertyInput::fields($part, ['key', 'request']);
            $key = PropertyInput::code($part['key'] ?? null, 'parts.key');
            if (isset($keys[$key])) PropertyInput::invalid('parts.key');
            $keys[$key] = true;
            if (!is_array($part['request'] ?? null) || isset($part['request']['source'])) PropertyInput::invalid('parts.request');
            $paymentRequest = $part['request'] + ['source' => ['kind' => $facts['kind'], 'id' => $facts['id'], 'expected_version' => $sourceState['version']]];
            $plan = $this->payments->previewClearing($supplier, $paymentRequest, $source);
            $carrier = \MyInvoice\Service\Property\Payments\PaymentHistoricalCarrierProof::fromCurrentPayment($this->db, $this->payments, $supplier, $paymentRequest, $source);
            $target = $journal->currentPayment($carrier);
            $plan['snapshot'] = $capacity->add($plan['snapshot'], $paymentRequest);
            $plan['preview_hash'] = PropertyInput::hash($plan['snapshot']);
            $paymentRequest = $plan['snapshot']['request'];
            $plans[] = ['key' => $key, 'request' => $paymentRequest, 'plan' => $plan, 'journal' => $target];
            $total = bcadd($total, $paymentRequest['amount_cents'], 0);
        }
        if (bccomp($total, $sourceState['available_cents'], 0) > 0) PaymentException::reject('source_capacity');
        $snapshot = ['request' => $request, 'source' => $facts, 'cells' => $source->cells, 'native' => $native, 'parts' => $plans,
            'settled_cents' => $total, 'available_cents' => bcsub($sourceState['available_cents'], $total, 0)];
        return ['status' => 'ready', 'source' => ['kind' => $facts['kind'], 'id' => $facts['id'], 'date' => $facts['date'], 'amount_cents' => $facts['amount_cents'], 'currency' => 'CZK'],
            'source_etag' => $facts['source_etag'], 'preview_hash' => PropertyInput::hash($snapshot), 'snapshot' => $snapshot];
    }
}
