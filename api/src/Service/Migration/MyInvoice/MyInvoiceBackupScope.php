<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MyInvoice;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use MyInvoice\Service\Migration\Myucto\MyuctoImportProfile;
use RuntimeException;

/** Selects one company from the shared MyInvoice graphs, before ID remapping. */
final class MyInvoiceBackupScope
{
    public const TABLES = [
        'currencies', 'revenue_categories', 'expense_categories', 'cost_centers',
        'clients', 'client_bank_accounts', 'projects', 'supplier_bank_accounts',
        'price_list_items', 'price_list_item_prices', 'price_list_customer_overrides',
        'recurring_invoice_templates', 'recurring_invoice_template_items',
        'invoices', 'work_reports', 'invoice_items', 'purchase_invoices', 'purchase_invoice_items',
        'bank_statements', 'bank_transactions', 'invoice_payments', 'payment_matches',
    ];

    public function select(array $all, int $supplierId, string $mode, array $history): array
    {
        $supplier = $all['supplier'][$supplierId] ?? throw new RuntimeException('Záloha neobsahuje vybranou firmu.');
        if (array_key_exists('accounting_mode', $supplier)) {
            throw new RuntimeException('Použijte zálohu původního MyInvoice; pro MyÚčto použijte Kompletní export dat.');
        }
        if (!in_array($mode, ['tax_evidence', 'double_entry'], true)) {
            throw new RuntimeException('Zadejte účetní režim tax_evidence nebo double_entry.');
        }
        $supplier = array_intersect_key($supplier, array_fill_keys([
            'id', 'company_name', 'ic', 'country_id', 'default_currency_id',
            'is_vat_payer', 'is_identified', 'taxpayer_type', 'vat_period',
        ], true));
        $supplier['accounting_mode'] = $mode;
        $selected = ['supplier' => [$supplierId => $supplier]];
        foreach (MyuctoImportProfile::GLOBAL_KEYS as $table => $_) {
            $selected[$table] = $all[$table] ?? [];
        }
        foreach (self::TABLES as $table) {
            $selected[$table] = [];
            foreach ($all[$table] ?? [] as $id => $row) {
                if ($table === 'bank_statements') {
                    $owner = $row['supplier_id'] ?? null;
                    if ($owner === null) {
                        $owner = $this->statementOwner($row, $all['currencies'] ?? []);
                    }
                    if ((string) $owner === (string) $supplierId) {
                        $row['supplier_id'] = $supplierId;
                        $selected[$table][$id] = $row;
                    }
                } elseif (array_key_exists('supplier_id', $row)) {
                    if ((string) $row['supplier_id'] === (string) $supplierId) {
                        $selected[$table][$id] = $row;
                    }
                } else {
                    $owner = MyuctoImportProfile::OWNERS[$table] ?? match ($table) {
                        'price_list_item_prices', 'price_list_customer_overrides' => ['price_list_item_id', 'price_list_items'],
                        'client_bank_accounts' => ['client_id', 'clients'],
                        default => throw new RuntimeException('Tabulka zálohy nemá známé vlastnictví: ' . $table . '.'),
                    };
                    [$column, $parent] = $owner;
                    if (isset($selected[$parent][MyuctoExportReader::id($row[$column] ?? null)])) {
                        $selected[$table][$id] = $row;
                    }
                }
            }
        }
        $selected['supplier_vat_status_history'] = $this->history($history, $supplierId);
        return $selected;
    }

    private function statementOwner(array $statement, array $accounts): int
    {
        $number = (string) ($statement['account_number'] ?? '');
        if (AccountNumberNormalizer::normalize($number) === '') {
            throw new RuntimeException('Bankovní výpis nemá účet pro určení firmy.');
        }
        // Offline counterpart of CashJournalRepository::matchingStatementIds. Uses the
        // shared account normalizer; here the candidates are rows from the backup.
        $owners = [];
        foreach ($accounts as $account) {
            $bank = trim((string) ($statement['bank_code'] ?? ''));
            $accountBank = trim((string) ($account['bank_code'] ?? ''));
            $iban = $account['iban'] ?? null;
            if ($bank !== '' && $accountBank !== '' && $bank !== $accountBank
                && !AccountNumberNormalizer::matchesAny($number, null, $iban)) {
                continue;
            }
            if (AccountNumberNormalizer::matchesAny($number, $account['account_number'] ?? null, $iban)) {
                $owners[MyuctoExportReader::id($account['supplier_id'] ?? null)] = true;
            }
        }
        if (count($owners) !== 1) {
            throw new RuntimeException('Bankovní výpis nemá jednoznačného vlastníka v záloze.');
        }
        return (int) array_key_first($owners);
    }

    private function history(array $history, int $supplierId): array
    {
        if ($history === [] || !array_is_list($history)) {
            throw new RuntimeException('Dodejte ověřenou historii DPH jako neprázdný JSON seznam.');
        }
        $rows = [];
        $dates = [];
        foreach ($history as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Historie DPH musí obsahovat objekty.');
            }
            $date = $row['effective_from'] ?? null;
            if (!is_string($date)
                || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $date, $match)
                || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])
                || isset($dates[$date])) {
                throw new RuntimeException('Historie DPH obsahuje neplatné nebo opakované datum.');
            }
            foreach (['is_vat_payer', 'is_identified'] as $field) {
                if (!in_array($row[$field] ?? null, [0, 1], true)) {
                    throw new RuntimeException('Historie DPH musí výslovně obsahovat příznaky 0 nebo 1.');
                }
            }
            $percent = $row['annual_deduction_percent'] ?? null;
            if (!array_key_exists('annual_deduction_percent', $row)
                || ($percent !== null && (!is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100))
                || ($row['is_vat_payer'] === 1 && $row['is_identified'] === 1)) {
                throw new RuntimeException('Historie DPH obsahuje neplatný stav nebo procento odpočtu.');
            }
            $dates[$date] = true;
            $rows[$index + 1] = ['id' => $index + 1, 'supplier_id' => $supplierId,
                'effective_from' => $date, 'is_vat_payer' => $row['is_vat_payer'],
                'is_identified' => $row['is_identified'], 'annual_deduction_percent' => $percent === null ? null : (string) $percent];
        }
        return $rows;
    }
}
