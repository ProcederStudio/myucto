<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyUctoRegression\Safety;

$root = dirname(__DIR__, 3);
$config = Config::load($root);
$name = (string) $config->get('db.name');
Safety::database($name, '');
Safety::host((string) $config->get('db.host'), null);
if (getenv('MYINVOICE_REGRESSION_SYNTHETIC_DB') !== '1' || $name !== ($argv[1] ?? '') || !str_starts_with($name, 'myucto_regcapture_')) {
    throw new RuntimeException('Explicit owned synthetic database required.');
}
$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
if ((int) $pdo->query('SELECT COUNT(*) FROM accounting_periods')->fetchColumn() !== 0 || (int) $pdo->query('SELECT COUNT(*) FROM supplier')->fetchColumn() !== 2) {
    throw new RuntimeException('Fresh ci-seed database required.');
}
$sid = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
$user = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
$currency = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id = {$sid} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
$rate = (int) $pdo->query('SELECT MIN(id) FROM vat_rates')->fetchColumn();
$pdo->prepare("UPDATE supplier SET taxpayer_type = 'po', accounting_mode = 'double_entry', ic = '12345678', dic = 'CZ12345678', financial_office_code = '451', cz_nace_code = '62020', opr_jmeno = 'Test', opr_prijmeni = 'Fixture', opr_postaveni = 'jednatel' WHERE id = ?")->execute([$sid]);
$container->get(AccountingModeRepository::class)->record($sid, '2025-01-01', 'double_entry');
$container->get(ChartOfAccountsSeeder::class)->seedForSupplier($sid);
$container->get(AccountingPeriodRepository::class)->create($sid, 2025, '2025-12-01', '2025-12-31');
$container->get(\MyInvoice\Repository\StatementDefinitionRepository::class)->setFunctionMapping($sid, '518', 'administration', $user);
$posting = $container->get(PostingService::class);
foreach ([['311', '602', 35000.25], ['518', '321', 5000.10]] as [$debit, $credit, $amount]) {
    $posting->postDocument($sid, 'manual', null, [
        ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
        ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
    ], ['entry_date' => '2025-12-15', 'posted_by' => $user, 'user_id' => $user]);
}
$pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code) VALUES (?, 'CZK', 'Secondary synthetic', 'Kč', 'CZK', 'CZK', 2, 1, 0, '1000000005', '0300')")->execute([$sid]);
$bank = (int) $pdo->lastInsertId();
$container->get(TaxReturnRepository::class)->create($sid, 2025, 'po', ['tax_paid_advances' => 1234.56, 'bank_account_id' => $bank], $user);
foreach ([['CZ', 'CZ12345679', '1', 20000.25, 4200.05, 21], ['DE', 'DE123456789', '20', 15000.00, 0.00, 0]] as $index => [$country, $dic, $classification, $base, $vat, $percent]) {
    $countryQuery = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
    $countryQuery->execute([$country]);
    $countryId = (int) $countryQuery->fetchColumn();
    $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email, language, currency_default_id, is_customer, is_vendor) VALUES (?, 'Synthetic regression customer', 'Test 1', 'Fixture', '11000', ?, ?, 'fixture@example.invalid', 'cs', ?, 1, 0)")->execute([$sid, $countryId, $dic, $currency]);
    $client = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by) VALUES (?, ?, 'invoice', ?, '2025-12-15', '2025-12-15', '2025-12-29', ?, 1, 0, ?, ?, ?, 'issued', ?, ?)")->execute([$sid, '202512000' . ($index + 1), $client, $currency, $base, $vat, $base + $vat, $classification, $user]);
    $invoice = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code) VALUES (?, 'Synthetic service', 1, 'ks', ?, ?, ?, ?, ?, ?, 0, ?)")->execute([$invoice, $base, $rate, $percent, $base, $vat, $base + $vat, $classification]);
}
echo "SYNTHETIC_CAPTURE_SEEDED\n";
