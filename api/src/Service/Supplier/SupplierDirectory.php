<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Bank\NonInvoiceBankTransactionScope;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Support\Sql\PayablePredicate;
use MyInvoice\Support\Sql\ReceivablePredicate;

/**
 * Seznam firem ve správě firem s řazením, filtry a urgencí pro účetní kancelář.
 *
 * Každý ukazatel je JEDEN agregační dotaz s GROUP BY přes celý seznam firem (žádný
 * dotaz na firmu v cyklu). Viditelnost firem řeší volající stejně jako u přepínače
 * firem ({@see \MyInvoice\Action\Settings\SettingsAction::listSuppliers()}); sem
 * přichází už odfiltrovaný seznam ID, nebo null pro „všechny".
 *
 * Urgence je vážený součet věcí, které čekají na účetní. Predikáty jsou tytéž jako
 * u akcí na úvodní stránce ({@see CrmAggregationService::actionItems()}), jen bez
 * uživatelských skrytí (dismissals), protože seznam patří kanceláři, ne jednomu uživateli:
 *   - přiznání DPH za poslední období, jehož termín už uplynul, není doložené jako
 *     podané ({@see self::WEIGHT_VAT_OVERDUE}); jen u firem, které DPH přes aplikaci
 *     podávají (mají aspoň jedno doložené podání), jinak by svítily všechny firmy,
 *     které podávají jinde;
 *   - totéž za období s termínem do {@see self::VAT_DUE_SOON_DAYS} dní;
 *   - vystavené faktury po splatnosti ({@see ReceivablePredicate});
 *   - přijaté faktury po splatnosti ({@see PayablePredicate});
 *   - nespárované příchozí platby za 90 dní (vlastnictví výpisu přes
 *     {@see BankStatementOwnershipResolver}, bez pohybů z {@see NonInvoiceBankTransactionScope});
 *   - koncepty přijatých faktur ke kontrole (bez čekajících na schválení).
 * Počty se do skóre započítávají jen do stropu, ať jedna firma se stovkou starých
 * nespárovaných plateb nepřebije firmu s nepodaným přiznáním.
 */
final class SupplierDirectory
{
    public const WEIGHT_VAT_OVERDUE = 100;
    public const WEIGHT_VAT_DUE_SOON = 30;
    public const WEIGHT_OVERDUE_PAYABLE = 4;
    public const WEIGHT_OVERDUE_RECEIVABLE = 3;
    public const WEIGHT_UNMATCHED_BANK = 1;
    public const WEIGHT_PURCHASE_DRAFT = 1;
    public const VAT_DUE_SOON_DAYS = 7;

    private const COUNT_CAP = 10;
    private const COUNT_CAP_LOW = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly OverduePolicy $overduePolicy,
    ) {}

    /**
     * @param list<int>|null $allowedIds null = bez omezení
     * @return list<array<string,mixed>>
     */
    public function list(?array $allowedIds, SupplierDirectoryQuery $query, \DateTimeImmutable $now): array
    {
        if ($allowedIds !== null) {
            $allowedIds = array_values(array_unique(array_filter(array_map('intval', $allowedIds), static fn (int $id): bool => $id > 0)));
            if ($allowedIds === []) {
                return [];
            }
        }

        $rows = $this->baseRows($allowedIds, $query);
        if ($rows === []) {
            return [];
        }
        $ids = array_keys($rows);
        $today = $now->format('Y-m-d');

        $clients = $this->countBy("SELECT supplier_id, COUNT(*) AS v FROM clients WHERE supplier_id IN ({in}) GROUP BY supplier_id", $ids);
        $invoiceStats = $this->invoiceStats($ids);
        $overdueOp = $this->overduePolicy->comparisonOperator();
        $receivables = $this->countBy(
            "SELECT i.supplier_id, COUNT(*) AS v FROM invoices i
              WHERE i.supplier_id IN ({in})
                AND " . ReceivablePredicate::overdueOpen('i', $overdueOp, '?') . "
              GROUP BY i.supplier_id",
            $ids, [$today]);
        $payables = $this->countBy(
            "SELECT pi.supplier_id, COUNT(*) AS v FROM purchase_invoices pi
              WHERE pi.supplier_id IN ({in})
                AND pi.status IN ('received', 'booked')" . PayablePredicate::excludeAdvanceVatDocument()
                . PayablePredicate::excludeFullySettled() . "
                AND pi.due_date $overdueOp ?
              GROUP BY pi.supplier_id",
            $ids, [$today]);
        $drafts = $this->countBy(
            "SELECT supplier_id, COUNT(*) AS v FROM purchase_invoices
              WHERE supplier_id IN ({in}) AND status = 'draft' AND approval_status <> 'pending'
              GROUP BY supplier_id",
            $ids);
        $bank = $this->unmatchedBank($ids, $today);
        $activity = $this->countBy("SELECT supplier_id, MAX(created_at) AS v FROM activity_log WHERE supplier_id IN ({in}) GROUP BY supplier_id", $ids, [], false);
        $vat = $this->vatStatus($ids, $now);

        $out = [];
        foreach ($rows as $sid => $r) {
            $counts = [
                'overdue_receivables'         => (int) ($receivables[$sid] ?? 0),
                'overdue_payables'            => (int) ($payables[$sid] ?? 0),
                'unmatched_bank_transactions' => (int) ($bank[$sid] ?? 0),
                'purchase_drafts'             => (int) ($drafts[$sid] ?? 0),
            ];
            $vatInfo = $vat[$sid] ?? null;
            $score = self::score($counts, $vatInfo['status'] ?? null);

            $out[] = [
                'id'               => $sid,
                'company_name'     => (string) $r['company_name'],
                'display_name'     => $r['display_name'] !== null ? (string) $r['display_name'] : null,
                'ic'               => $r['ic'] !== null ? (string) $r['ic'] : null,
                'dic'              => $r['dic'] !== null ? (string) $r['dic'] : null,
                'is_vat_payer'     => (bool) $r['is_vat_payer'],
                'email'            => (string) $r['email'],
                'accounting_mode'  => (string) $r['accounting_mode'],
                'country_iso'      => (string) $r['country_iso'],
                'clients_count'    => (int) ($clients[$sid] ?? 0),
                'invoices_count'   => (int) ($invoiceStats[$sid]['count'] ?? 0),
                'last_invoice_date' => $invoiceStats[$sid]['last'] ?? null,
                'last_activity_at' => isset($activity[$sid]) ? (string) $activity[$sid] : null,
                'urgency'          => [
                    'score' => $score,
                    'level' => self::level($score),
                    'vat'   => $vatInfo,
                ] + $counts,
            ];
        }

        if ($query->urgent !== null) {
            $out = array_values(array_filter($out, static fn (array $row): bool => ($row['urgency']['score'] > 0) === $query->urgent));
        }

        return self::sort($out, $query);
    }

    /**
     * @param array<string,int> $counts
     */
    public static function score(array $counts, ?string $vatStatus): int
    {
        return ($vatStatus === 'overdue' ? self::WEIGHT_VAT_OVERDUE : 0)
            + ($vatStatus === 'due_soon' ? self::WEIGHT_VAT_DUE_SOON : 0)
            + self::WEIGHT_OVERDUE_PAYABLE * min($counts['overdue_payables'] ?? 0, self::COUNT_CAP)
            + self::WEIGHT_OVERDUE_RECEIVABLE * min($counts['overdue_receivables'] ?? 0, self::COUNT_CAP)
            + self::WEIGHT_UNMATCHED_BANK * min($counts['unmatched_bank_transactions'] ?? 0, self::COUNT_CAP_LOW)
            + self::WEIGHT_PURCHASE_DRAFT * min($counts['purchase_drafts'] ?? 0, self::COUNT_CAP_LOW);
    }

    public static function level(int $score): string
    {
        return match (true) {
            $score >= 50 => 'high',
            $score >= 15 => 'medium',
            $score > 0   => 'low',
            default      => 'none',
        };
    }

    /**
     * Řádky přicházejí seřazené podle názvu (collation DB), takže stabilní usort
     * nechává shody v abecedním pořadí. Prázdné hodnoty jsou vždy na konci.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function sort(array $rows, SupplierDirectoryQuery $query): array
    {
        $sign = $query->dir === 'asc' ? 1 : -1;
        $key = match ($query->sort) {
            'name'          => null,
            'last_invoice'  => static fn (array $r): ?string => $r['last_invoice_date'],
            'last_activity' => static fn (array $r): ?string => $r['last_activity_at'],
            'overdue'       => static fn (array $r): int => $r['urgency']['overdue_receivables'],
            default         => static fn (array $r): int => $r['urgency']['score'],
        };
        if ($key === null) {
            return $sign === 1 ? $rows : array_reverse($rows);
        }
        usort($rows, static function (array $a, array $b) use ($key, $sign): int {
            $av = $key($a);
            $bv = $key($b);
            if ($av === null || $bv === null) {
                return ($av === null) <=> ($bv === null);
            }
            return $sign * ($av <=> $bv);
        });

        return $rows;
    }

    /**
     * @param list<int>|null $allowedIds
     * @return array<int, array<string,mixed>>
     */
    private function baseRows(?array $allowedIds, SupplierDirectoryQuery $query): array
    {
        $where = [];
        $params = [];
        if ($allowedIds !== null) {
            $where[] = 's.id IN (' . implode(',', array_fill(0, count($allowedIds), '?')) . ')';
            array_push($params, ...$allowedIds);
        }
        if ($query->q !== '') {
            $like = '%' . addcslashes($query->q, '%_\\') . '%';
            $where[] = '(s.company_name LIKE ? OR s.display_name LIKE ? OR s.ic LIKE ? OR s.dic LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        // Filtr „plátce" je dnešní stav, proto stačí živá cache supplier.is_vat_payer.
        if ($query->vatPayer !== null) {
            $where[] = 's.is_vat_payer = ?';
            $params[] = $query->vatPayer ? 1 : 0;
        }
        if ($query->accountingMode !== null) {
            $where[] = 's.accounting_mode = ?';
            $params[] = $query->accountingMode;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, s.display_name, s.ic, s.dic, s.is_vat_payer,
                    s.email, s.accounting_mode, c.iso2 AS country_iso
               FROM supplier s
               JOIN countries c ON c.id = s.country_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY s.company_name, s.id'
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[(int) $r['id']] = $r;
        }

        return $rows;
    }

    /**
     * Počet všech faktur (jako dosavadní sloupec seznamu) a datum poslední vystavené
     * faktury v definici tržeb CRM ({@see CrmAggregationService::REV_TYPES}).
     *
     * @param list<int> $ids
     * @return array<int, array{count:int, last:?string}>
     */
    private function invoiceStats(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT supplier_id, COUNT(*) AS cnt,
                    MAX(CASE WHEN status IN " . CrmAggregationService::REV_STATUS . "
                              AND invoice_type IN " . CrmAggregationService::REV_TYPES . " THEN issue_date END) AS last_issue
               FROM invoices
              WHERE supplier_id IN ($in)
              GROUP BY supplier_id"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['supplier_id']] = [
                'count' => (int) $r['cnt'],
                'last'  => $r['last_issue'] !== null ? (string) $r['last_issue'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,int>
     */
    private function unmatchedBank(array $ids, string $today): array
    {
        // `bs.supplier_id = s.id OR bs.supplier_id IS NULL` je důsledek predikátu
        // vlastnictví a drží přístup přes idx_bs_supplier (viz PortfolioVolumeCounter).
        return $this->countBy(
            "SELECT s.id AS supplier_id, COUNT(bt.id) AS v
               FROM supplier s
               JOIN bank_statements bs
                 ON (bs.supplier_id = s.id OR bs.supplier_id IS NULL)
                AND " . BankStatementOwnershipResolver::sqlForColumn('s.id') . "
               JOIN bank_transactions bt
                 ON bt.statement_id = bs.id
                AND bt.match_status = 'unmatched'
                AND " . \MyInvoice\Service\Bank\BankNoticeLifecycle::activeSql('bt') . "
                AND bt.amount > 0
                AND bt.posted_at >= DATE_SUB(?, INTERVAL 90 DAY)
                AND NOT " . NonInvoiceBankTransactionScope::sql('s.id', 'bt.id') . "
              WHERE s.id IN ({in})
              GROUP BY s.id",
            $ids, [$today], true, true);
    }

    /**
     * Stav přiznání DPH za poslední období po termínu a za nejbližší období.
     * Doložené podání se hledá stejně jako {@see \MyInvoice\Repository\TaxSubmissionRepository::findLatestForPeriod()}
     * (stav submitted/accepted, měsíc vs. čtvrtletí přesně), plátcovství za období
     * přes {@see VatStatusService::payerDuringExpr()}.
     *
     * @param list<int> $ids
     * @return array<int, array{status:string, deadline:string, days:int, period:string}>
     */
    private function vatStatus(array $ids, \DateTimeImmutable $now): array
    {
        $slots = self::vatSlots($now);
        $union = [];
        $params = [];
        foreach ($slots as $slot) {
            $union[] = 'SELECT ? AS cycle, ? AS slot, CAST(? AS DATE) AS p_from, CAST(? AS DATE) AS p_to,
                               CAST(? AS SIGNED) AS p_year, CAST(? AS SIGNED) AS p_month, CAST(? AS SIGNED) AS p_quarter,
                               CAST(? AS DATE) AS deadline';
            array_push($params, $slot['cycle'], $slot['slot'], $slot['from'], $slot['to'],
                $slot['year'], $slot['month'], $slot['quarter'], $slot['deadline']);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        array_push($params, ...$ids);

        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id AS supplier_id, per.slot, per.deadline, per.p_year, per.p_month, per.p_quarter,
                    " . VatStatusService::payerDuringExpr('s.id', 'per.p_from', 'per.p_to', 's.is_vat_payer') . " AS payer,
                    EXISTS (SELECT 1 FROM tax_submissions ts
                             WHERE ts.supplier_id = s.id AND ts.form_code = 'dphdp3'
                               AND ts.status IN ('submitted','accepted')
                               AND ts.period_year = per.p_year
                               AND ((per.p_month IS NOT NULL AND ts.period_month = per.p_month AND ts.period_quarter IS NULL)
                                 OR (per.p_month IS NULL AND ts.period_month IS NULL AND ts.period_quarter = per.p_quarter))) AS filed,
                    EXISTS (SELECT 1 FROM tax_submissions tsa
                             WHERE tsa.supplier_id = s.id AND tsa.form_code = 'dphdp3'
                               AND tsa.status IN ('submitted','accepted')) AS files_in_app
               FROM supplier s
               JOIN (" . implode(' UNION ALL ', $union) . ") per
                 ON per.cycle = (CASE WHEN s.vat_period = 'quarterly' THEN 'quarterly' ELSE 'monthly' END)
              WHERE s.id IN ($in)"
        );
        $stmt->execute($params);

        $bySupplier = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $bySupplier[(int) $r['supplier_id']][(string) $r['slot']] = $r;
        }

        $out = [];
        foreach ($bySupplier as $sid => $bySlot) {
            $past = $bySlot['past'] ?? null;
            $next = $bySlot['next'] ?? null;
            if ($past === null || $next === null || !(bool) $past['files_in_app']) {
                continue;
            }
            $pick = null;
            $status = 'ok';
            if ((bool) $past['payer'] && !(bool) $past['filed']) {
                $pick = $past;
                $status = 'overdue';
            } elseif ((bool) $next['payer']) {
                $pick = $next;
                $days = self::daysUntil($now, (string) $next['deadline']);
                if (!(bool) $next['filed'] && $days <= self::VAT_DUE_SOON_DAYS) {
                    $status = 'due_soon';
                }
            }
            if ($pick === null) {
                continue;
            }
            $out[$sid] = [
                'status'   => $status,
                'deadline' => (string) $pick['deadline'],
                'days'     => self::daysUntil($now, (string) $pick['deadline']),
                'period'   => $pick['p_month'] !== null
                    ? sprintf('%04d-%02d', (int) $pick['p_year'], (int) $pick['p_month'])
                    : sprintf('%04d-Q%d', (int) $pick['p_year'], (int) $pick['p_quarter']),
            ];
        }

        return $out;
    }

    /**
     * Poslední uplynulý a nejbližší termín přiznání DPH pro měsíční i čtvrtletní
     * cyklus. Termín je 25. den po skončení období posunutý na pracovní den
     * ({@see CzechWorkingDays::deadline()}).
     *
     * @return list<array{cycle:string, slot:string, from:string, to:string, year:int, month:?int, quarter:?int, deadline:string}>
     */
    public static function vatSlots(\DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $base = $now->modify('first day of this month');
        $slots = [];
        foreach (['monthly', 'quarterly'] as $cycle) {
            $past = null;
            $next = null;
            for ($i = -4; $i <= 4; $i++) {
                $deadlineMonth = $base->modify(($i >= 0 ? '+' : '') . $i . ' month');
                $dm = (int) $deadlineMonth->format('n');
                if ($cycle === 'quarterly' && !in_array($dm, [1, 4, 7, 10], true)) {
                    continue;
                }
                $deadline = CzechWorkingDays::deadline((int) $deadlineMonth->format('Y'), $dm);
                $periodEnd = $deadlineMonth->modify('-1 day');
                $periodStart = $cycle === 'quarterly'
                    ? $deadlineMonth->modify('-3 month')
                    : $deadlineMonth->modify('-1 month');
                $slot = [
                    'cycle'    => $cycle,
                    'from'     => $periodStart->format('Y-m-d'),
                    'to'       => $periodEnd->format('Y-m-d'),
                    'year'     => (int) $periodEnd->format('Y'),
                    'month'    => $cycle === 'monthly' ? (int) $periodEnd->format('n') : null,
                    'quarter'  => $cycle === 'quarterly' ? intdiv((int) $periodEnd->format('n') + 2, 3) : null,
                    'deadline' => $deadline,
                ];
                if ($deadline < $today) {
                    $past = ['slot' => 'past'] + $slot;
                } elseif ($next === null) {
                    $next = ['slot' => 'next'] + $slot;
                }
            }
            if ($past !== null) {
                $slots[] = $past;
            }
            if ($next !== null) {
                $slots[] = $next;
            }
        }

        return $slots;
    }

    private static function daysUntil(\DateTimeImmutable $now, string $date): int
    {
        return (int) (new \DateTimeImmutable($now->format('Y-m-d')))->diff(new \DateTimeImmutable($date))->format('%r%a');
    }

    /**
     * @param list<int> $ids
     * @param list<mixed> $extraParams parametry dotazu (před seznamem ID, je-li $paramsFirst)
     * @return array<int, int|string>
     */
    private function countBy(string $sqlTemplate, array $ids, array $extraParams = [], bool $asInt = true, bool $paramsFirst = false): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(str_replace('{in}', $in, $sqlTemplate));
        $stmt->execute($paramsFirst ? array_merge($extraParams, $ids) : array_merge($ids, $extraParams));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['v'] !== null) {
                $out[(int) $r['supplier_id']] = $asInt ? (int) $r['v'] : (string) $r['v'];
            }
        }

        return $out;
    }
}
