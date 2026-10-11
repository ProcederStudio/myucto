<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingRuleValidator;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\OperationType;
use PDO;

final class SampleRuleGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly ExpenseClassificationRuleRepository $expenseRules,
        private readonly BankPostingRuleRepository $bankRules,
        private readonly BankPostingRuleValidator $bankValidator,
        private readonly ExpenseClassificationService $classification,
    ) {}

    public function generate(int $supplierId, int $userId, bool $advanced, callable $track): array
    {
        $counts = ['expense_rules' => 0, 'bank_posting_rules' => 0];
        if (!$advanced) return $counts;
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Pravidla ukázkových dat vyžadují otevřenou transakci.');
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.company_name FROM clients c
             JOIN sample_data_entries s ON s.supplier_id = c.supplier_id AND s.entity_id = c.id AND s.entity_type = "vendor"
             WHERE c.supplier_id = ? AND c.is_vendor = 1'
        );
        $stmt->execute([$supplierId]);
        $vendors = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $vendor) {
            $vendors[(string) $vendor['company_name']] = (int) $vendor['id'];
        }

        $specs = [
            ['Ukázka: nájem kanceláře a skladu', 'Modelový Pronájem s.r.o.', 'Nájem kanceláře', ExpenseKind::Service, '518', null],
            ['Ukázka: cloudové služby', 'Sample Cloud GmbH', 'Cloudové služby', ExpenseKind::Service, '518', null],
            ['Ukázka: kancelářský a obalový materiál', 'Sample Office s.r.o.', 'materiál', ExpenseKind::Material, '501', null],
            ['Ukázka: pořízení dlouhodobého serveru', 'Demo Hardware s.r.o.', 'server', ExpenseKind::FixedAsset, '042', 80000.01],
        ];
        $expensePayloads = [];
        foreach ($specs as $index => [$name, $vendorName, $fragment, $kind, $account, $minimum]) {
            if (!isset($vendors[$vendorName])) {
                throw new \DomainException('Chybí evidovaný ukázkový dodavatel pro pravidlo: ' . $vendorName);
            }
            $expensePayloads[] = [
                'name' => $name,
                'vendor_client_id' => $vendors[$vendorName],
                'description_contains' => $fragment,
                'expense_kind' => $kind->value,
                'target_account_code' => $this->classification->analyticForExpenseAccount($supplierId, $account),
                'amount_min' => $minimum,
                'tax_deductible' => true,
                'recurring_prepaid' => false,
                'application_mode' => 'suggest',
                'priority' => 20 + $index * 10,
                'is_active' => true,
            ];
        }

        $bankSpecs = [
            ['Ukázka: bankovní poplatky', 'outgoing', 'Poplatek za', '568', '221', 'Bankovní poplatek', OperationType::BANK_FEE, 1000.0],
            ['Ukázka: připsané bankovní úroky', 'incoming', 'Připsaný úrok', '221', '662', 'Připsaný úrok z běžného účtu', OperationType::BANK_INTEREST, 5000.0],
            ['Ukázka: nájem placený přímo ze smlouvy', 'outgoing', 'Nájem dle smlouvy DEMO-NAJEM', '518', '221', 'Nájem dle smlouvy bez předpisu přijatou fakturou', OperationType::BANK_RULE_CUSTOM, 50000.0],
        ];
        $bankPayloads = [];
        foreach ($bankSpecs as $index => [$name, $direction, $fragment, $debit, $credit, $description, $operation, $maximum]) {
            $bankPayloads[] = $this->bankValidator->normalizeRule($supplierId, [
                'name' => $name,
                'direction' => $direction,
                'message_contains' => $fragment,
                'debit_account_code' => $debit,
                'credit_account_code' => $credit,
                'description' => $description,
                'operation_type' => $operation,
                'amount_min' => 0.01,
                'amount_max' => $maximum,
                'priority' => 20 + $index * 10,
                'applies_currency' => 'CZK',
            ]);
        }
        foreach ($expensePayloads as $payload) {
            $track('expense_rule', $this->expenseRules->insert($supplierId, $payload, $userId));
            $counts['expense_rules']++;
        }
        foreach ($bankPayloads as $payload) {
            $track('bank_posting_rule', $this->bankRules->insert($supplierId, $payload, $userId));
            $counts['bank_posting_rules']++;
        }
        return $counts;
    }
}
