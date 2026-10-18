<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollEnforcementFactsRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use PDO;

/**
 * Hromadné ověření a zahájení srážek u případů „Přijato — čeká na ověření"
 * (typicky exekuce převzaté z předchozího mzdového programu).
 *
 * Jednotlivě to znamenalo u každého případu projít pohledávky, zaškrtnout
 * ověření, uložit evidenci a spustit „Zahájit srážení" s výběrem usnesení —
 * u sedmnácti převzatých exekucí přes sto kliknutí, a mzdový běh mezitím
 * srážel 0 Kč. Služba nic nezkracuje: používá tytéž kroky repozitáře, takže
 * platí všechny jeho pojistky (doložené strany a instrukce příjemce, otevřené
 * období, verze řádků). Rozhodnutím pro zahájení je usnesení, ze kterého je
 * zapsaný soud nebo exekutor případu.
 *
 * Případ s chybějícím povinným údajem se nezahájí; `readiness()` vrátí, co
 * chybí, a klient nabídne proklik do detailu případu.
 */
final readonly class EnforcementBulkActivationService
{
    /** Co může případu chybět k zahájení srážení (klientský union `EnforcementBulkMissing`). */
    public const MISSING = ['claims', 'order_issued_on', 'priority_date', 'legal_parties'];

    public function __construct(
        private Connection $db,
        private PayrollEnforcementRepository $repository,
        private EnforcementCaseLifecycle $lifecycle,
    ) {}

    /**
     * @return list<array{
     *   case_id:int,row_version:int,employee_id:int,employee_name:string,case_key:string,
     *   effective_from:string,claim_count:int,missing:list<string>,legal_message:?string,
     *   decision_document_id:?int
     * }>
     */
    public function readiness(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id, c.row_version, c.employee_id, e.full_name, c.case_key, c.effective_from,
                    (SELECT COUNT(*) FROM payroll_enforcement_claims cl
                      WHERE cl.supplier_id = c.supplier_id AND cl.case_id = c.id AND cl.is_active = 1) AS claim_count,
                    (SELECT COUNT(*) FROM payroll_enforcement_claims cl
                      WHERE cl.supplier_id = c.supplier_id AND cl.case_id = c.id AND cl.is_active = 1
                        AND cl.legal_basis = 'statutory' AND cl.order_issued_on IS NULL) AS missing_order,
                    (SELECT COUNT(*) FROM payroll_enforcement_claims cl
                      WHERE cl.supplier_id = c.supplier_id AND cl.case_id = c.id AND cl.is_active = 1
                        AND cl.priority_date IS NULL) AS missing_priority
               FROM payroll_enforcement_cases c
               JOIN payroll_employees e ON e.supplier_id = c.supplier_id AND e.id = c.employee_id
              WHERE c.supplier_id = ? AND c.status = 'received'
              ORDER BY e.full_name, c.id",
        );
        $stmt->execute([$supplierId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $case) {
            $caseId = (int) $case['id'];
            $effectiveFrom = (string) $case['effective_from'];
            $missing = [];
            if ((int) $case['claim_count'] === 0) {
                $missing[] = 'claims';
            }
            if ((int) $case['missing_order'] > 0) {
                $missing[] = 'order_issued_on';
            }
            if ((int) $case['missing_priority'] > 0) {
                $missing[] = 'priority_date';
            }
            $legalMessage = null;
            try {
                (new PayrollEnforcementFactsRepository($this->db))
                    ->assertLegalRecipientReadyForActivation($supplierId, $caseId, $effectiveFrom);
            } catch (\InvalidArgumentException|\DomainException $e) {
                $legalMessage = $e->getMessage();
                $missing[] = 'legal_parties';
            }
            $document = $this->decisionDocument($supplierId, $caseId, $effectiveFrom);
            if ($document === null && !in_array('legal_parties', $missing, true)) {
                $missing[] = 'legal_parties';
            }
            $rows[] = [
                'case_id' => $caseId,
                'row_version' => (int) $case['row_version'],
                'employee_id' => (int) $case['employee_id'],
                'employee_name' => (string) $case['full_name'],
                'case_key' => (string) $case['case_key'],
                'effective_from' => $effectiveFrom,
                'claim_count' => (int) $case['claim_count'],
                'missing' => $missing,
                'legal_message' => $legalMessage,
                'decision_document_id' => $document === null ? null : $document['id'],
            ];
        }

        return $rows;
    }

    /**
     * Ověří pohledávky a evidenci a zahájí srážení u každého připraveného
     * případu. Každý případ ve vlastním savepointu: chyba jednoho nezruší
     * ostatní.
     *
     * @param list<array{case_id:int,row_version:int}> $items
     * @return list<array{case_id:int,status:string,message:?string}>
     */
    public function activate(int $supplierId, array $items, ?int $userId): array
    {
        $ready = [];
        foreach ($this->readiness($supplierId) as $row) {
            $ready[$row['case_id']] = $row;
        }
        $results = [];
        $pdo = $this->db->pdo();
        foreach ($items as $item) {
            $caseId = $item['case_id'];
            $row = $ready[$caseId] ?? null;
            if ($row === null) {
                $results[] = ['case_id' => $caseId, 'status' => 'failed', 'message' => 'Případ už nečeká na ověření.'];
                continue;
            }
            if ($row['row_version'] !== $item['row_version']) {
                $results[] = ['case_id' => $caseId, 'status' => 'failed', 'message' => 'Případ mezitím někdo změnil. Obnovte seznam.'];
                continue;
            }
            if ($row['missing'] !== [] || $row['decision_document_id'] === null) {
                $results[] = ['case_id' => $caseId, 'status' => 'failed', 'message' => 'Případu chybí povinné údaje.'];
                continue;
            }
            $nested = $pdo->inTransaction();
            $nested ? $pdo->exec('SAVEPOINT enforcement_bulk_activation') : $pdo->beginTransaction();
            try {
                $this->verifyClaims($supplierId, $caseId);
                $version = $this->caseVersion($supplierId, $caseId);
                $case = $this->repository->updateCaseEvidence($supplierId, $caseId, true, true, $version, $userId);
                $document = $this->decisionDocument($supplierId, $caseId, $row['effective_from'])
                    ?? throw new \DomainException('Usnesení případu není dostupné.');
                $this->repository->transition(
                    $supplierId,
                    $caseId,
                    EnforcementCaseCommand::MarkFinal,
                    (int) $case['row_version'],
                    null,
                    new EnforcementDecisionDocumentReference($document['id'], $document['sha256']),
                    $userId,
                    $this->lifecycle,
                    null,
                );
                $nested ? $pdo->exec('RELEASE SAVEPOINT enforcement_bulk_activation') : $pdo->commit();
                $results[] = ['case_id' => $caseId, 'status' => 'activated', 'message' => null];
            } catch (\Throwable $e) {
                if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                    throw $e;
                }
                if ($nested && $pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT enforcement_bulk_activation');
                    $pdo->exec('RELEASE SAVEPOINT enforcement_bulk_activation');
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $results[] = ['case_id' => $caseId, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function verifyClaims(int $supplierId, int $caseId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, legal_basis, category, outstanding_minor_units, maintenance_weight_minor_units,
                    order_issued_on, row_version
               FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND case_id = ? AND is_active = 1
              ORDER BY id',
        );
        $stmt->execute([$supplierId, $caseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $claim) {
            $voluntary = $claim['legal_basis'] === 'voluntary_agreement';
            $this->repository->updateUnusedClaim(
                $supplierId,
                $caseId,
                (int) $claim['id'],
                [
                    'legal_basis' => (string) $claim['legal_basis'],
                    'category' => (string) $claim['category'],
                    'outstanding_minor_units' => (int) $claim['outstanding_minor_units'],
                    'maintenance_weight_minor_units' => $claim['maintenance_weight_minor_units'] === null
                        ? null
                        : (int) $claim['maintenance_weight_minor_units'],
                    'order_issued_on' => $claim['order_issued_on'],
                    'legal_title_verified' => !$voluntary,
                    'order_or_notice_delivered' => !$voluntary,
                    'priority_classification_verified' => true,
                    'agreement_verified' => $voluntary,
                    'due_monetary_claim_verified' => !$voluntary,
                ],
                (int) $claim['row_version'],
            );
        }
    }

    private function caseVersion(int $supplierId, int $caseId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_enforcement_cases WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $caseId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Usnesení, ze kterého je zapsaný aktuální soud nebo exekutor případu.
     *
     * @return array{id:int,sha256:string}|null
     */
    private function decisionDocument(int $supplierId, int $caseId, string $effectiveOn): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT party.source_document_id, party.source_document_sha256
               FROM payroll_enforcement_case_parties party
              WHERE party.supplier_id = ? AND party.case_id = ?
                AND party.party_role IN ('executor', 'court')
                AND party.effective_from <= ?
                AND party.source_document_id IS NOT NULL
              ORDER BY party.party_role = 'executor' DESC, party.effective_from DESC, party.revision_no DESC
              LIMIT 1",
        );
        $stmt->execute([$supplierId, $caseId, $effectiveOn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['source_document_sha256'] ?? null)) {
            return null;
        }

        return ['id' => (int) $row['source_document_id'], 'sha256' => (string) $row['source_document_sha256']];
    }
}
