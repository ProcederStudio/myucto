<?php

declare(strict_types=1);

/**
 * Křížové kontroly účetních a daňových výstupů — vrstva L4 auditního plánu (fáze F7).
 *
 * Použití:
 *   php api/bin/cross-check.php                       (všechny firmy, všechny uzavřené roky)
 *   php api/bin/cross-check.php --year=2025
 *   php api/bin/cross-check.php --supplier=1 --year=2024
 *   php api/bin/cross-check.php --accepted=soubor.json  (odsouhlasené známé rozdíly)
 *
 * Odsouhlasený rozdíl: JSON seznam {"supplier": 1, "check": "DPH smír 12/2024", "difference": 1.04,
 * "reason": "…"}. Shoduje se firma, začátek názvu kontroly a rozdíl NA HALÉŘ — jakákoliv změna
 * částky je zase nesoulad. Vypíše se jako [PŘIJATO] s důvodem, bránu neshodí. Soubor patří
 * k datům instalace, ne do repozitáře.
 *
 * Spočítá totéž číslo DVĚMA NEZÁVISLÝMI cestami a porovná (viz {@see CrossCheckSuite}):
 * přiznání DPH ↔ KH ↔ SH ↔ obrat 343, aktiva ↔ pasiva, VH v rozvaze ↔ VH ve VZZ,
 * VZZ ↔ hlavní kniha, Σ MD ↔ Σ D v obratové předvaze.
 *
 * READ-ONLY — buildery sestavují výkazy in-memory, nic se nezapisuje. Lze pustit
 * i proti produkční databázi.
 *
 * EXIT KÓD:
 *   0 = vše sedí
 *   1 = nalezen nesoulad
 *   2 = nebylo co kontrolovat (žádné uzavřené období)
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Report\CrossCheckSuite;

$opts = getopt('', ['supplier::', 'year::', 'accepted::']);

$accepted = [];
if (isset($opts['accepted']) && $opts['accepted'] !== false) {
    $decoded = json_decode((string) @file_get_contents((string) $opts['accepted']), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "CHYBA: soubor odsouhlasených rozdílů nejde načíst: {$opts['accepted']}\n");
        exit(1);
    }
    $accepted = $decoded;
}
$acceptedReason = static function (int $supplierId, array $r) use ($accepted): ?string {
    foreach ($accepted as $a) {
        if ((int) ($a['supplier'] ?? 0) === $supplierId
            && str_starts_with((string) $r['label'], (string) ($a['check'] ?? ''))
            && abs(round((float) ($r['difference'] ?? 0), 2) - round((float) ($a['difference'] ?? NAN), 2)) < 0.005
        ) {
            return (string) ($a['reason'] ?? '');
        }
    }
    return null;
};

$container = Bootstrap::buildApp()->getContainer();
$db = $container->get(Connection::class);
$suite = $container->get(CrossCheckSuite::class);

// Bez --supplier všechny firmy s uzavřeným obdobím — dřív jen firma 1, takže na
// instalaci s více firmami brána ostatní vůbec nehlídala.
$suppliers = isset($opts['supplier'])
    ? [(int) $opts['supplier']]
    : array_map('intval', $db->pdo()->query(
        'SELECT DISTINCT supplier_id FROM accounting_periods
          WHERE status IN (' . AccountingPeriodStatus::closedSqlList() . ')
          ORDER BY supplier_id'
    )->fetchAll(\PDO::FETCH_COLUMN));

echo 'Křížové kontroly — databáze: ' . $db->pdo()->query('SELECT DATABASE()')->fetchColumn()
    . ', firmy: ' . ($suppliers === [] ? '—' : implode(', ', $suppliers)) . "\n";

$fmt = static fn (?float $n): string => $n === null ? '—' : number_format($n, 2, ',', ' ');

$mismatches = 0;
$checked = 0;
$acceptedCount = 0;

foreach ($suppliers as $supplierId) {
    $years = isset($opts['year']) ? [(int) $opts['year']] : $suite->closedYears($supplierId);
    foreach ($years as $year) {
        echo "\n=== Firma {$supplierId}, rok {$year} ===\n";
        foreach ($suite->run($supplierId, $year) as $r) {
            if ($r['skipped']) {
                printf("[SKIP] %s — %s\n", $r['label'], (string) $r['note']);
                continue;
            }
            $checked++;
            if ($r['ok']) {
                printf("[ OK ] %s\n", $r['label']);
                continue;
            }
            $reason = $acceptedReason($supplierId, $r);
            if ($reason !== null) {
                printf("[PŘIJATO] %s — rozdíl %s: %s\n", $r['label'], $fmt($r['difference']), $reason);
                $acceptedCount++;
                continue;
            }
            $mismatches++;
            printf(
                "[NESOULAD] %s\n           %s = %s, %s = %s, rozdíl %s\n",
                $r['label'],
                $r['a_label'],
                $fmt($r['a']),
                $r['b_label'],
                $fmt($r['b']),
                $fmt($r['difference']),
            );
            if ($r['note'] !== null) {
                echo '           ' . (string) $r['note'] . "\n";
            }
        }
    }
}

if ($checked === 0) {
    fwrite(STDERR, "\nCHYBA: nevyhodnotila se ANI JEDNA kontrola (žádná firma s uzavřeným obdobím).\n");
    exit(2);
}

printf("\nVyhodnoceno %d kontrol, nesouladů: %d, odsouhlasených rozdílů: %d\n", $checked, $mismatches, $acceptedCount);
exit($mismatches > 0 ? 1 : 0);
