<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Service\Migration\MyInvoice\MyInvoiceBackupConverter;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;

$help = <<<'HELP'
Převod stávající databázové zálohy MyInvoice pro import jedné firmy do MyÚčta.
php api/bin/myinvoice-backup-convert.php --backup=db.zip --company=ID \
  --accounting-mode=tax_evidence --vat-history=historie.json --output=import.zip \
  [--files=pdf.zip] [--files=originaly.zip]

Výstup nahrajte přes Importy → MyÚčto; nejprve proveďte kontrolu nanečisto.
SQL se pouze čte, žádný příkaz zálohy se nespouští. Zdroj ani cílová DB se nemění.
Účetní režim: tax_evidence nebo double_entry; nastavte jej také v cílové firmě.
Historie DPH je ověřený JSON seznam effective_from, is_vat_payer, is_identified,
annual_deduction_percent (číslo nebo null); příznaky jsou čísla 0/1.
MyInvoice historii nevede, je nutné ji zadat a sladit s cílovou firmou.
Archivy originálů mají cesty storage/purchase-invoices/... a storage/invoices-imported/...
Chybějící odkazovaný originál znamená chybu. Výstupní soubor nesmí existovat.
Heslo vstupních ZIPů: MYINVOICE_BACKUP_PASSWORD. Heslo výstupu:
MYINVOICE_BACKUP_OUTPUT_PASSWORD; není-li nastavené, převezme heslo vstupů.
Hesla nepatří do argumentů příkazu. Výstup neobsahuje uživatele ani přístupové klíče.
Limity: každý ZIP 2 GiB, SQL 512 MiB, podporovaná data celé instance 64 MiB / 100 000 řádků.
Výstupní vybraná data a originály společně nejvýše 64 MiB / 100 000 řádků.
HELP;

try {
    $options = [];
    $files = [];
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
        if ($arg === '--help') {
            fwrite(STDOUT, $help . PHP_EOL);
            exit(0);
        }
        if (!preg_match('/\A--(backup|company|accounting-mode|vat-history|output|files)=(.+)\z/D', $arg, $match)) {
            throw new InvalidArgumentException($help);
        }
        if ($match[1] === 'files') {
            if (count($files) >= 16) {
                throw new InvalidArgumentException('Lze přiložit nejvýše 16 archivů originálů.');
            }
            $files[] = $match[2];
        } elseif (isset($options[$match[1]])) {
            throw new InvalidArgumentException('Duplicitní argument: ' . $match[1] . '.');
        } else {
            $options[$match[1]] = $match[2];
        }
    }
    foreach (['backup', 'company', 'accounting-mode', 'vat-history', 'output'] as $key) {
        if (!isset($options[$key])) {
            throw new InvalidArgumentException($help);
        }
    }
    if (!is_file($options['vat-history']) || filesize($options['vat-history']) > 1_048_576) {
        throw new InvalidArgumentException('Historie DPH musí být JSON soubor do 1 MiB.');
    }
    $history = json_decode((string) file_get_contents($options['vat-history']), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($history)) {
        throw new InvalidArgumentException('Historie DPH musí být JSON seznam.');
    }
    $password = getenv('MYINVOICE_BACKUP_PASSWORD');
    $password = $password === false ? '' : $password;
    $outputPassword = getenv('MYINVOICE_BACKUP_OUTPUT_PASSWORD');
    $outputPassword = $outputPassword === false ? $password : $outputPassword;
    $report = (new MyInvoiceBackupConverter())->convert(
        $options['backup'], $files, $options['output'], MyuctoExportReader::id($options['company']),
        $options['accounting-mode'], $history, $password, $outputPassword
    );
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (JsonException $e) {
    fwrite(STDERR, "Neplatný JSON historie DPH nebo nepřenositelné kódování SQL zálohy.\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
