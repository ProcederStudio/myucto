<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEnforcementFactsRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementDecisionDocumentReference;
use PDO;

/**
 * Převzatá exekuce rovnou ve stavu, ve kterém ji vedl předchozí mzdový program.
 *
 * Exekuci, kterou předchozí program srážel, plátce mzdy už vykonává: soud nebo exekutor
 * ji doručil, pořadí je dané a příjemce dostával platby. MyÚčto ji proto převezme jako
 * pokračování, ne jako nový příkaz, který by účetní musela ručně doložit a zahájit
 * u každého případu. Podkladem všech kroků je dokument „Převzetí exekuce“ s údaji ze
 * zdroje (uložený v Dokumentech, otisk SHA-256): z něj jsou zapsané strany případu
 * (soud/exekutor a oprávněný podle příjemce ze zdroje), instrukce příjemce i rozhodnutí
 * o zahájení. Pojistky aktivace ({@see PayrollEnforcementFactsRepository::assertLegalRecipientReadyForActivation()})
 * platí beze změny, jen je splní převzatá evidence.
 *
 * - Běžící případ (platnost trvá ke dni začátku vedení mezd a pohledávka není doplacená)
 *   se zahájí a povolí se odesílání příjemci.
 * - Případ, který ve zdroji skončil (platnost do dne před začátkem vedení mezd, nebo
 *   doplacená pohledávka), se zastaví s důvodem ze zdroje.
 * - Případ bez použitelného příjemce (účtu) zůstane „přijato“; protokol ho vypíše.
 */
final class PayrollTakeoverEnforcementActivation
{
    public const ACTIVATED = 'activated';
    public const STOPPED = 'stopped';
    public const PENDING = 'pending';
    public const DEFERRED = 'deferred';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEnforcementRepository $enforcement,
        private readonly EnforcementCaseLifecycle $lifecycle,
        private readonly DocumentIngestService $ingest,
        private readonly DocumentStorage $storage,
    ) {}

    /**
     * Rozhodnutí ze zdroje: `stop` s důvodem, nebo `activate`.
     *
     * Veřejné a statické, aby pravidlo šlo zavolat z testu a nevznikla jeho kopie.
     *
     * @param array<string,mixed> $record záznam srážky ({@see PayrollTakeoverDeductionsWriter})
     * @param ?string $startPeriod začátek vedení mezd (`YYYY-MM-DD`)
     * @return array{action:string,reason:?string}
     */
    public static function decide(array $record, ?string $startPeriod): array
    {
        $validTo = is_string($record['valid_to'] ?? null) ? (string) $record['valid_to'] : null;
        if ($validTo !== null && $startPeriod !== null && $validTo < $startPeriod) {
            return ['action' => 'stop', 'reason' => "Ve zdroji platnost skončila {$validTo}, před začátkem vedení mezd v MyÚčtu."];
        }
        if ((int) ($record['total_minor'] ?? 0) > 0 && (int) ($record['outstanding_minor'] ?? 0) <= 0) {
            return ['action' => 'stop', 'reason' => 'Ve zdroji je pohledávka doplacená.'];
        }

        return ['action' => 'activate', 'reason' => null];
    }

    /**
     * @param array<string,mixed> $record
     * @param ?int $paymentAccountId účet příjemce v katalogu institucí, `null` = příjemce bez účtu
     * @param bool $insolvency běžící insolvence: doloží se jako exekuce, ale odloží se bez srážení,
     *        protože srážky v oddlužení vede měsíční insolvenční evidence, ne exekuční případ
     */
    public function apply(
        int $supplierId,
        int $caseId,
        array $record,
        ?int $paymentAccountId,
        ?int $userId,
        PayrollTakeoverPolicy $policy,
        bool $insolvency = false,
    ): string {
        if ($userId === null) {
            return self::PENDING;
        }
        $decision = self::decide($record, $this->startPeriod($supplierId));
        if ($decision['action'] === 'activate' && $paymentAccountId === null) {
            return self::PENDING;
        }
        $case = $this->enforcement->findCase($supplierId, $caseId)
            ?? throw new \DomainException('exekuční případ nebyl po založení nalezen.');
        $effectiveFrom = (string) $case['effective_from'];
        $document = $this->document($supplierId, $caseId, $record, $decision, $policy, $userId);
        $reference = new EnforcementDecisionDocumentReference($document['id'], $document['sha256']);
        $facts = new PayrollEnforcementFactsRepository($this->db);

        if ($decision['action'] === 'stop') {
            $this->enforcement->transition($supplierId, $caseId, EnforcementCaseCommand::Stop,
                (int) $case['row_version'], $policy->note($decision['reason'] ?? ''), $reference, $userId, $this->lifecycle);

            return self::STOPPED;
        }

        $recipient = is_array($record['recipient'] ?? null) ? $record['recipient'] : [];
        $name = mb_substr(trim((string) ($recipient['name'] ?? '')) ?: (string) $record['title'], 0, 190);
        $partyReference = is_string($recipient['reference'] ?? null) ? mb_substr((string) $recipient['reference'], 0, 190) : null;
        $authority = $facts->appendParty($supplierId, $caseId, 'executor', $effectiveFrom, $name, $partyReference,
            $document['id'], $document['sha256'], $userId);
        $facts->appendParty($supplierId, $caseId, 'beneficiary', $effectiveFrom, $name, $partyReference,
            $document['id'], $document['sha256'], $userId);
        $facts->appendRecipientInstruction($supplierId, $caseId, $effectiveFrom, (int) $authority['id'],
            (int) $paymentAccountId, $document['id'], $document['sha256'], $policy->note('příjemce srážky'), $userId);
        $this->verifyClaims($supplierId, $caseId, $record);
        $case = $this->enforcement->updateCaseEvidence($supplierId, $caseId, true, true,
            (int) $this->version($supplierId, $caseId), $userId);
        $case = $this->enforcement->transition($supplierId, $caseId, EnforcementCaseCommand::MarkFinal,
            (int) $case['row_version'], null, $reference, $userId, $this->lifecycle);
        if ($insolvency) {
            $this->enforcement->transition($supplierId, $caseId, EnforcementCaseCommand::DeferNoWithholding,
                (int) $case['row_version'], $policy->note('insolvence; srážky v oddlužení vede insolvenční evidence měsíce, ne exekuční případ.'),
                $reference, $userId, $this->lifecycle);

            return self::DEFERRED;
        }
        $this->enforcement->transition($supplierId, $caseId, EnforcementCaseCommand::AuthorizeRemittance,
            (int) $case['row_version'], null, $reference, $userId, $this->lifecycle);

        return self::ACTIVATED;
    }

    /**
     * Pohledávky ověřené převzatou evidencí. Datum vydání příkazu zdroj nevede; použije se
     * den doručení prvnímu plátci, ke kterému byl příkaz nejpozději vydaný.
     *
     * @param array<string,mixed> $record
     */
    private function verifyClaims(int $supplierId, int $caseId, array $record): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, legal_basis, category, outstanding_minor_units, maintenance_weight_minor_units,
                    order_issued_on, row_version
               FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND case_id = ? AND is_active = 1
              ORDER BY id',
        );
        $stmt->execute([$supplierId, $caseId]);
        $issued = is_string($record['priority_date'] ?? null) ? (string) $record['priority_date']
            : (is_string($record['valid_from'] ?? null) ? (string) $record['valid_from'] : null);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $claim) {
            $voluntary = $claim['legal_basis'] === 'voluntary_agreement';
            $this->enforcement->updateUnusedClaim($supplierId, $caseId, (int) $claim['id'], [
                'legal_basis' => (string) $claim['legal_basis'],
                'category' => (string) $claim['category'],
                'outstanding_minor_units' => (int) $claim['outstanding_minor_units'],
                'maintenance_weight_minor_units' => $claim['maintenance_weight_minor_units'] === null
                    ? null : (int) $claim['maintenance_weight_minor_units'],
                'order_issued_on' => $claim['order_issued_on'] ?? ($voluntary ? null : $issued),
                'legal_title_verified' => !$voluntary,
                'order_or_notice_delivered' => !$voluntary,
                'priority_classification_verified' => true,
                'agreement_verified' => $voluntary,
                'due_monetary_claim_verified' => !$voluntary,
            ], (int) $claim['row_version']);
        }
    }

    /**
     * Dokument „Převzetí exekuce“ s údaji ze zdroje, podklad všech převzatých kroků.
     *
     * @param array<string,mixed> $record
     * @param array{action:string,reason:?string} $decision
     * @return array{id:int,sha256:string}
     */
    private function document(int $supplierId, int $caseId, array $record, array $decision, PayrollTakeoverPolicy $policy, int $userId): array
    {
        $recipient = is_array($record['recipient'] ?? null) ? $record['recipient'] : [];
        $money = static fn (mixed $minor): string => number_format(((int) $minor) / 100, 2, ',', ' ') . ' Kč';
        $lines = [
            'Převzetí exekuce z předchozího mzdového programu',
            '',
            'Zdroj: ' . $policy->label,
            'Exekuční případ v MyÚčtu: #' . $caseId,
            'Srážka ve zdroji: ' . $record['title'] . ' (reference ' . $record['reference'] . ')',
            'Osobní číslo: ' . implode(', ', (array) ($record['personal_numbers'] ?? [])),
            'Platnost ve zdroji: ' . ($record['valid_from'] ?? '-') . ' až ' . ($record['valid_to'] ?? 'neomezeně'),
            'Doručeno prvnímu plátci: ' . ($record['priority_date'] ?? '-'),
            'Kategorie pohledávky: ' . $record['category'],
            'Celková výše: ' . $money($record['total_minor'] ?? 0) . ', zůstatek: ' . $money($record['outstanding_minor'] ?? 0),
            'Sraženo ve zdroji v obdobích: ' . (implode(', ', (array) ($record['withheld_periods'] ?? [])) ?: '-'),
            'Příjemce: ' . ($recipient['name'] ?? '-')
                . (is_string($recipient['account'] ?? null) ? ', účet ' . $recipient['account'] . '/' . ($recipient['bank_code'] ?? '') : '')
                . (is_string($recipient['variable_symbol'] ?? null) ? ', VS ' . $recipient['variable_symbol'] : '')
                . (is_string($recipient['reference'] ?? null) ? ', rozhodnutí ' . $recipient['reference'] : ''),
            '',
            $decision['action'] === 'stop'
                ? 'Převod případ zastavuje: ' . $decision['reason']
                : 'Převod případ zahajuje jako pokračování srážek, které předchozí program vykonával.',
        ];
        $bytes = implode("\n", $lines) . "\n";
        $sha256 = hash('sha256', $bytes);
        $existing = $this->documentBySha($supplierId, $sha256);
        if ($existing !== null) {
            return $existing;
        }
        $tmp = $this->storage->tmpPath($supplierId);
        if (file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Doklad převzetí exekuce se nepodařilo uložit.');
        }
        $folderId = $this->ingest->ensureFolderPath($supplierId, null, ['Mzdy', 'Exekuce', 'Převzaté z ' . $policy->label], $userId);
        $this->ingest->ingestUploadedTemp($tmp, $supplierId, $folderId, sprintf('prevzeti-exekuce-%d.txt', $caseId), $userId);

        return $this->documentBySha($supplierId, $sha256)
            ?? throw new \RuntimeException('Doklad převzetí exekuce se nepodařilo uložit do Dokumentů.');
    }

    /** @return array{id:int,sha256:string}|null */
    private function documentBySha(int $supplierId, string $sha256): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, sha256 FROM documents WHERE supplier_id = ? AND sha256 = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
        );
        $stmt->execute([$supplierId, $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['id' => (int) $row['id'], 'sha256' => (string) $row['sha256']] : null;
    }

    private function version(int $supplierId, int $caseId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM payroll_enforcement_cases WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $caseId]);

        return (int) $stmt->fetchColumn();
    }

    /** Začátek vedení mezd firmy (`YYYY-MM-DD`), nebo `null`. */
    public function startPeriod(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
