<?php

declare(strict_types=1);

/**
 * Ověření zřetězené auditní stopy — § 33a ZoÚ.
 *
 *   php api/bin/verify-audit-chain.php
 *   MYINVOICE_DB_NAME=<db> php api/bin/verify-audit-chain.php
 *
 * Read-only. Exit 0 = řetěz sedí, 1 = porušený, 2 = není co ověřovat.
 *
 * POZOR na výklad výsledku: zelený běh znamená, že od zavedení řetězu nikdo záznam
 * nezměnil ANI nepřepočítal celý řetěz. Před útočníkem s právem zápisu, který přepočítá
 * všechno, chrání až kotva mimo databázi — tu tenhle nástroj nenahrazuje.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\ActivityLogHashChain;

$chain = Bootstrap::buildApp()->getContainer()->get(ActivityLogHashChain::class);

if (!$chain->isAvailable()) {
    fwrite(STDERR, "Sloupce hash-chainu chybí — spusť migraci 1157.\n");
    exit(2);
}

$r = $chain->verify();

echo 'Auditní stopa (§ 33a) — zřetězení hashem', PHP_EOL, PHP_EOL;
echo '  Ověřeno záznamů: ', $r['checked'], PHP_EOL;
echo '  Bez ochrany (před zavedením řetězu): ', $r['unprotected'], PHP_EOL;
if ($r['pending'] > 0) {
    echo '  Čeká na zapečetění (commitnuté, hash doplní další pečetění): ', $r['pending'], PHP_EOL;
}
if ($r['first_protected_id'] !== null) {
    echo '  Řetěz začíná záznamem #', $r['first_protected_id'], PHP_EOL;
}
echo PHP_EOL;

if ($r['checked'] === 0) {
    echo "Žádný zapečetěný záznam — není co ověřovat.", PHP_EOL;
    echo "Zelený běh nad prázdným řetězem nic nedokazuje.", PHP_EOL;
    exit(2);
}

if ($r['ok']) {
    echo '[ OK ] Řetěz je neporušený.', PHP_EOL;
    if ($r['unprotected'] > 0) {
        echo PHP_EOL, 'Pozn.: ', $r['unprotected'], ' starších záznamů hash nemá a chráněné NENÍ. ',
             'Zpětný dopočet by nic nedokázal — hash spočtený dnes nad daty, ',
             'která mohla být kdykoli změněna, dodá jen zdání důvěryhodnosti.', PHP_EOL;
    }
    exit(0);
}

echo '[PORUŠENO] Nálezů: ', count($r['broken']), PHP_EOL;
foreach (array_slice($r['broken'], 0, 50) as $b) {
    echo '    záznam #', $b['id'], ' — ', $b['reason'], PHP_EOL;
}
exit(1);
