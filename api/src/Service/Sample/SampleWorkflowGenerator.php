<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;

final class SampleWorkflowGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePaymentService $payments,
        private readonly CreditNoteOffsetService $creditNotes,
        private readonly BankPostingService $bankPosting,
    ) {}

    public function generate(int $supplierId, int $userId, DateTimeImmutable $today, bool $advanced, callable $track): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Ukázkové úhrady a koncepty vyžadují otevřenou transakci.');
        $stmt = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id=? AND invoice_type='credit_note' ORDER BY id");
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $creditId) {
            $result = $this->creditNotes->applyForInvoice($supplierId, (int) $creditId, $userId);
            if ($result['offset_id'] === null) throw new \RuntimeException('Ukázkový dobropis nelze započíst: ' . $result['reason']);
            $track('credit_note_offset', (int) $result['offset_id']);
        }
        $stmt = $pdo->prepare("SELECT * FROM bank_statements WHERE supplier_id=? AND currency='CZK' ORDER BY statement_date,id");
        $stmt->execute([$supplierId]);
        $statements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $journalIds = [];
        $transactionCount = 0;
        foreach (['invoice', 'purchase_invoice'] as $type) {
            $sale = $type === 'invoice';
            $table = $sale ? 'invoices' : 'purchase_invoices';
            $partner = $sale ? 'client_id' : 'vendor_id';
            $unpaid = $sale ? "i.invoice_type='invoice' AND i.paid_total=0 AND i.status IN ('issued','sent')" : "i.document_kind='invoice' AND i.status IN ('received','booked')";
            $stmt = $pdo->prepare("SELECT i.*,c.company_name,cur.code AS currency FROM $table i JOIN clients c ON c.id=i.$partner JOIN currencies cur ON cur.id=i.currency_id WHERE i.supplier_id=? AND $unpaid ORDER BY i.issue_date DESC,i.id DESC");
            $stmt->execute([$supplierId]);
            $documents = array_slice($stmt->fetchAll(PDO::FETCH_ASSOC), 4);
            foreach ($documents as $doc) {
                $statement = null;
                foreach ($statements as $candidate) {
                    if ($candidate['statement_date'] >= $doc['issue_date']) { $statement = $candidate; break; }
                }
                if ($advanced && $statement === null) throw new \RuntimeException('Pro úhradu ukázkového dokladu chybí výpis.');
                $date = min($statement['statement_date'] ?? $today->format('Y-m-d'), (new DateTimeImmutable($doc['issue_date']))->modify('+14 days')->format('Y-m-d'));
                $foreignAmount = (float) $doc['amount_to_pay'];
                $localAmount = round($foreignAmount * ($doc['currency'] === 'CZK' ? 1 : (float) $doc['exchange_rate']), 2);
                $txId = null;
                if ($advanced) {
                    $signed = $sale ? $localAmount : -$localAmount;
                    $fingerprint = hash('sha256', "sample-settlement|$supplierId|$type|{$doc['id']}");
                    $pdo->prepare('INSERT INTO bank_transactions (statement_id,source,posted_at,amount,currency,variable_symbol,counterparty_account,counterparty_bank,counterparty_name,description,bank_ref,import_fingerprint,matched_invoice_id,match_status,matched_at,matched_by) VALUES (?,"statement",?,?,"CZK",?,"1000000005","0100",?,?,?,?,?,"manual",NOW(),?)')->execute([
                        $statement['id'], $date, $signed, $doc['varsymbol'], $doc['company_name'],
                        'Úhrada ukázkové faktury ' . $doc['varsymbol'], 'SAMPLE-' . substr($fingerprint, 0, 24), $fingerprint,
                        $sale ? $doc['id'] : null, $userId,
                    ]);
                    $txId = (int) $pdo->lastInsertId();
                    $transactionCount++;
                }
                if ($sale) {
                    $this->payments->recordPayment((int) $doc['id'], $foreignAmount, $date, ['source'=>$advanced ? 'bank' : 'manual','bank_transaction_id'=>$txId,'variable_symbol'=>$doc['varsymbol'],'created_by'=>$userId]);
                } else {
                    if ($txId !== null) {
                        $pdo->prepare('INSERT INTO payment_matches (supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type,matched_by_user_id) VALUES (?,?,?,?,"manual",?)')->execute([$supplierId,$txId,$doc['id'],$localAmount,$userId]);
                    }
                    $paymentCurrencyId = $advanced ? (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id=$supplierId AND code='CZK' LIMIT 1")->fetchColumn() : (int) $doc['currency_id'];
                    $pdo->prepare('UPDATE purchase_invoices SET status="paid",paid_at=?,payment_currency_id=?,paid_amount_payment_ccy=?,paid_amount_invoice_ccy=? WHERE supplier_id=? AND id=?')->execute([$date,$paymentCurrencyId,$advanced ? $localAmount : $foreignAmount,$foreignAmount,$supplierId,$doc['id']]);
                }
                if ($txId !== null) {
                    $entryId = $this->bankPosting->postMatched($supplierId, $txId, $userId);
                    if ($entryId === null) throw new \RuntimeException('Úhrada ukázkové faktury se nezaúčtovala: ' . $doc['varsymbol']);
                    $journalIds[] = $entryId;
                    $track('journal_entry', $entryId);
                }
            }
        }
        if ($statements !== []) {
            $balance = (float) $statements[0]['prev_balance'];
            foreach ($statements as $statement) {
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(amount,0)),0) AS incoming,COALESCE(SUM(GREATEST(-amount,0)),0) AS outgoing,COUNT(*) AS n,SUM(matched_invoice_id IS NOT NULL OR EXISTS(SELECT 1 FROM payment_matches m WHERE m.bank_transaction_id=bank_transactions.id AND m.supplier_id=?)) AS matched FROM bank_transactions WHERE statement_id=?');
                $stmt->execute([$supplierId,$statement['id']]);
                $totals = $stmt->fetch(PDO::FETCH_ASSOC);
                $next = round($balance + (float) $totals['incoming'] - (float) $totals['outgoing'],2);
                $pdo->prepare('UPDATE bank_statements SET prev_balance=?,curr_balance=?,credit_total=?,debit_total=?,transaction_count=?,matched_count=? WHERE supplier_id=? AND id=?')->execute([$balance,$next,$totals['incoming'],$totals['outgoing'],$totals['n'],$totals['matched'],$supplierId,$statement['id']]);
                $balance = $next;
            }
        }
        $stmt = $pdo->prepare('SELECT p.id AS project_id,c.id AS client_id,c.currency_default_id AS currency_id FROM projects p JOIN clients c ON c.id=p.client_id WHERE c.supplier_id=? AND c.is_customer=1 ORDER BY p.id LIMIT 4');
        $stmt->execute([$supplierId]);
        $drafts = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $project) {
            $id = $this->invoices->createDraft([
                'client_id'=>(int) $project['client_id'],'project_id'=>(int) $project['project_id'],'currency_id'=>(int) $project['currency_id'],
                'issue_date'=>$today->format('Y-m-d'),'tax_date'=>$today->format('Y-m-d'),'due_date'=>$today->modify('+14 days')->format('Y-m-d'),
                'invoice_type'=>'invoice','prices_include_vat'=>false,'language'=>'cs','auto_send_reminders'=>false,
                'note_above_items'=>'Rozpracovaný výkaz práce pro školení. Doplňte odpracované časy a položky.',
            ],$userId);
            $track('invoice',$id);
            $drafts++;
        }
        return ['drafts'=>$drafts,'transaction_count'=>$transactionCount,'journal_entry_ids'=>$journalIds];
    }
}
