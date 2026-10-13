<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\TaxOfficeBankAccounts;
use MyInvoice\Service\Tax\VatPaymentInstruction;

/**
 * Daně firmy do platebního příkazu: DPH z podaných přiznání a zálohy na daň z příjmů
 * (§ 38a ZDP) z předpisů. Mzdové odvody FÚ se platí mzdovými dávkami, sem nepatří.
 *
 * Příjemce, VS i částka se vždy odvozují na serveru. Klient posílá jen ID závazku,
 * takže si do příkazu nemůže podstrčit jiný účet ani částku.
 */
final class TaxPaymentCandidates
{
    /** Jak daleko zpět se hledají podaná přiznání k úhradě. */
    private const VAT_LOOKBACK_DAYS = 400;

    /** Zálohy po splatnosti (nezaplacené) i nejbližší nadcházející. */
    private const ADVANCE_PAST_DAYS = 120;
    private const ADVANCE_AHEAD_DAYS = 120;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $ordered = $this->orderedAt($supplierId);
        $out = [];

        $vatSince = $today->modify('-' . self::VAT_LOOKBACK_DAYS . ' days')->format('Y-m-d');
        foreach (VatPaymentInstruction::unpaidReturns($this->db->pdo(), $supplierId, $vatSince) as $vat) {
            $paid = (float) ($vat['payment']['paid_amount'] ?? 0);
            $out[] = $this->candidate(
                'vat',
                (int) $vat['submission_id'],
                'DPH ' . $vat['period_label'],
                round((float) $vat['payable'] - $paid, 2),
                $vat['due_date'],
                $vat['account'],
                $vat['variable_symbol'],
                $ordered['vat'][(int) $vat['submission_id']] ?? null,
            );
        }

        foreach ($this->advances($supplierId, $today) as $advance) {
            $advance['ordered_at'] = $ordered['advance'][(int) $advance['id']] ?? null;
            $out[] = $advance;
        }

        usort($out, static fn (array $a, array $b): int => [$a['due_date'] ?? '9999', $a['key']] <=> [$b['due_date'] ?? '9999', $b['key']]);
        return $out;
    }

    /**
     * Položky platebního příkazu pro vybrané daně. Ignoruje ID, která nejsou mezi
     * kandidáty (zaplacené, cizí firmy, bez účtu), a vrací je jako přeskočené.
     *
     * @param list<int> $vatIds
     * @param list<int> $advanceIds
     * @return array{0:list<array<string,mixed>>, 1:list<array<string,mixed>>}
     */
    public function items(int $supplierId, array $vatIds, array $advanceIds, string $orderCurrency, ?string $batchKs): array
    {
        $byKey = [];
        foreach ($this->list($supplierId) as $candidate) {
            $byKey[$candidate['key']] = $candidate;
        }

        $items = [];
        $skipped = [];
        $requested = array_merge(
            array_map(static fn (int $id): string => 'vat:' . $id, $vatIds),
            array_map(static fn (int $id): string => 'advance:' . $id, $advanceIds),
        );
        foreach (array_unique($requested) as $key) {
            [$kind, $id] = explode(':', $key);
            $skip = static function (string $reason) use (&$skipped, $id, $kind): void {
                $skipped[] = ['id' => (int) $id, 'reason' => $reason, 'document' => $kind === 'vat' ? 'tax_vat' : 'tax_advance'];
            };
            $candidate = $byKey[$key] ?? null;
            if ($candidate === null || $candidate['amount'] <= 0) {
                $skip('nothing_to_pay');
                continue;
            }
            if ($orderCurrency !== 'CZK') {
                $skip('currency_mismatch');
                continue;
            }
            if ($candidate['account_number'] === null || $candidate['variable_symbol'] === null) {
                $skip('no_account');
                continue;
            }
            $items[] = [
                'purchase_invoice_id'     => null,
                'tax_submission_id'       => $kind === 'vat' ? (int) $id : null,
                'tax_advance_schedule_id' => $kind === 'advance' ? (int) $id : null,
                'payee_name'              => $candidate['office_name'],
                'payee_account_number'    => $candidate['account_number'],
                'payee_bank_code'         => $candidate['bank_code'],
                'payee_iban'              => $candidate['iban'],
                'payee_bic'               => null,
                'amount'                  => $candidate['amount'],
                'currency'                => 'CZK',
                'variable_symbol'         => $candidate['variable_symbol'],
                'constant_symbol'         => $batchKs,
                'specific_symbol'         => null,
                'message'                 => mb_substr($candidate['label'], 0, 140),
                'account_verified'        => 'na',
            ];
        }
        return [$items, $skipped];
    }

    /** @return list<array<string,mixed>> */
    private function advances(int $supplierId, \DateTimeImmutable $today): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.due_date, s.amount, s.paid_amount, s.taxpayer_type, s.variable_symbol,
                    s.period_year, s.seq_no, sup.financial_office_code, sup.dic
               FROM tax_advance_schedules s
               JOIN supplier sup ON sup.id = s.supplier_id
              WHERE s.supplier_id = ? AND s.advance_kind = 'tax' AND s.status = 'planned'
                AND s.due_date BETWEEN ? AND ?
              ORDER BY s.due_date, s.id"
        );
        $stmt->execute([
            $supplierId,
            $today->modify('-' . self::ADVANCE_PAST_DAYS . ' days')->format('Y-m-d'),
            $today->modify('+' . self::ADVANCE_AHEAD_DAYS . ' days')->format('Y-m-d'),
        ]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $isPo = $row['taxpayer_type'] === 'po';
            $account = TaxOfficeBankAccounts::account(
                $row['financial_office_code'] !== null ? (string) $row['financial_office_code'] : null,
                $isPo ? TaxOfficeBankAccounts::PREFIX_DPPO : TaxOfficeBankAccounts::PREFIX_DPFO,
            );
            $vs = preg_replace('/\D/', '', (string) ($row['variable_symbol'] ?? '')) ?: preg_replace('/\D/', '', (string) ($row['dic'] ?? ''));
            $out[] = $this->candidate(
                'advance',
                (int) $row['id'],
                sprintf('Záloha %s %d/%d', $isPo ? 'DPPO' : 'DPFO', (int) $row['seq_no'], (int) $row['period_year']),
                round((float) $row['amount'] - (float) $row['paid_amount'], 2),
                (string) $row['due_date'],
                $account,
                $vs !== '' ? $vs : null,
                null,
            );
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|null $account
     * @return array<string,mixed>
     */
    private function candidate(string $kind, int $id, string $label, float $amount, ?string $dueDate, ?array $account, ?string $vs, ?string $orderedAt): array
    {
        return [
            'key'             => $kind . ':' . $id,
            'kind'            => $kind,
            'id'              => $id,
            'label'           => $label,
            'amount'          => $amount,
            'due_date'        => $dueDate,
            'office_name'     => $account['office_name'] ?? null,
            'account_number'  => $account !== null ? $account['prefix'] . '-' . $account['number'] : null,
            'bank_code'       => $account['bank_code'] ?? null,
            'iban'            => $account['iban'] ?? null,
            'account'         => $account['account'] ?? null,
            'variable_symbol' => $vs,
            'ordered_at'      => $orderedAt,
        ];
    }

    /** @return array{vat:array<int,string>,advance:array<int,string>} */
    private function orderedAt(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT poi.tax_submission_id, poi.tax_advance_schedule_id, MAX(po.created_at) AS ordered_at
               FROM payment_order_items poi
               JOIN payment_orders po ON po.id = poi.payment_order_id
              WHERE po.supplier_id = ? AND po.archived_at IS NULL
                AND (poi.tax_submission_id IS NOT NULL OR poi.tax_advance_schedule_id IS NOT NULL)
              GROUP BY poi.tax_submission_id, poi.tax_advance_schedule_id'
        );
        $stmt->execute([$supplierId]);
        $out = ['vat' => [], 'advance' => []];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['tax_submission_id'] !== null) {
                $out['vat'][(int) $row['tax_submission_id']] = (string) $row['ordered_at'];
            } else {
                $out['advance'][(int) $row['tax_advance_schedule_id']] = (string) $row['ordered_at'];
            }
        }
        return $out;
    }
}
