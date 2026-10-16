<?php

declare(strict_types=1);

/**
 * Cron — e-mailová urgence klientovi na otevřené vyžádání chybějícího dokladu
 * (Fáze F, audit 2026-07 P2/M).
 *
 * Použití:
 *   php api/bin/cron-document-request-reminders.php                # default --days=3 --cooldown=7
 *   php api/bin/cron-document-request-reminders.php --days=5
 *   php api/bin/cron-document-request-reminders.php --dry-run
 *
 * Filtry:
 *   --days=N      požadavek musí být starší než N dní (default 3)
 *   --cooldown=N  od poslední urgence musí uplynout aspoň N dní (default 7)
 *   --dry-run     jen vypíše, co by se odeslalo, nic nedělá
 *
 * Vybrané požadavky: status='requested', created_at < NOW() - N dní,
 * (last_reminder_at IS NULL OR last_reminder_at < NOW() - cooldown dní).
 *
 * Příjemci: uživatelé role 'client' přiřazení k firmě přes user_suppliers
 * (portálový přístup — DocumentRequestRepository::clientRecipientEmails).
 * Firma bez portálového uživatele (klient se do systému nikdy nepřihlásil) →
 * přeskočena, počítá se jako "no_recipients", ne chyba.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SupplierEmailContext;

// Parse args
$days = 3;
$cooldown = 7;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')                     { $dryRun = true; continue; }
    if (preg_match('/^--days=(\d+)$/', $arg, $m))      { $days = max(1, (int) $m[1]); continue; }
    if (preg_match('/^--cooldown=(\d+)$/', $arg, $m))  { $cooldown = max(0, (int) $m[1]); continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$container = Bootstrap::buildContainer();

/** @var DocumentRequestRepository $repo */
$repo = $container->get(DocumentRequestRepository::class);
/** @var Mailer $mailer */
$mailer = $container->get(Mailer::class);
/** @var ActivityLogger $logger */
$logger = $container->get(ActivityLogger::class);
/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
/** @var \MyInvoice\Service\Tenant\TenantUrlResolver $tenantUrls */
$tenantUrls = $container->get(\MyInvoice\Service\Tenant\TenantUrlResolver::class);

$supplierContext = new SupplierEmailContext($conn);

$run = CronRun::start($conn->pdo(), 'cron-document-request-reminders');
$startedAt = microtime(true);

$candidates = $repo->dueForReminder($days, $cooldown);
echo "[" . date('Y-m-d H:i:s') . "] cron-document-request-reminders --days={$days} --cooldown={$cooldown}"
    . ($dryRun ? ' --dry-run' : '') . " — found " . count($candidates) . " candidates\n";

$report = ['days' => $days, 'cooldown' => $cooldown, 'dry_run' => $dryRun, 'candidates' => count($candidates), 'sent' => 0, 'no_recipients' => 0, 'errors' => 0];

if (empty($candidates)) {
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  (nothing to do, {$ms} ms)\n";
    $logger->log('cron.document_request_reminders', null, null, null, $report);
    $run->finish('ok', $report);
    exit(0);
}

foreach ($candidates as $c) {
    $id = (int) $c['id'];
    $supplierId = (int) $c['supplier_id'];
    $supplierName = trim((string) ($c['supplier_display_name'] ?: $c['supplier_name']));
    $daysAgo = (int) floor((time() - strtotime((string) $c['created_at'])) / 86400);

    if ($dryRun) {
        printf("  [DRY] #%d [%s] %s — vytvořeno před %d dny\n", $id, $supplierName, (string) $c['description'], $daysAgo);
        continue;
    }

    $to = $repo->clientRecipientEmails($supplierId);
    if (empty($to)) {
        $report['no_recipients']++;
        printf("  - #%d [%s] — žádný portálový uživatel (client role), přeskočeno\n", $id, $supplierName);
        continue;
    }

    try {
        $vars = [
            // Bez `supplier` by Mailer sáhl po svém fallbacku MIN(id) FROM supplier
            // a upomínka by odešla s logem a SMTP profilem cizí firmy. ID tu máme,
            // filtrujeme jím i příjemce o pár řádků výš.
            'supplier'           => $supplierContext->forSupplier($supplierId),
            'supplier_name'      => $supplierName,
            'description'        => (string) $c['description'],
            'amount'             => $c['amount'] !== null ? (float) $c['amount'] : null,
            'deadline'           => $c['deadline'],
            'requested_days_ago' => $daysAgo,
            'portal_link'        => $tenantUrls->forSupplier($supplierId, 'portal', '/portal/document-requests'),
        ];
        $mailer->sendTemplate('document_request_reminder', 'cs', $to, $vars, templateSupplierId: $supplierId);
        $repo->bumpReminder($id);
        $logger->log('document_request.reminder_sent', null, 'document_request', $id, [
            'to' => $to, 'days_ago' => $daysAgo,
        ], null, null, $supplierId);
        $report['sent']++;
        printf("  \xE2\x9C\x93 #%d [%s] \xE2\x86\x92 %s (%d days)\n", $id, $supplierName, implode(', ', $to), $daysAgo);
    } catch (\Throwable $e) {
        $report['errors']++;
        $logger->log('document_request.reminder_failed', null, 'document_request', $id, [
            'error' => mb_substr($e->getMessage(), 0, 500),
        ], null, null, $supplierId);
        fprintf(STDERR, "  \xE2\x9C\x97 #%d [%s] — %s\n", $id, $supplierName, $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): sent={$report['sent']}, no_recipients={$report['no_recipients']}, errors={$report['errors']}\n";

$logger->log('cron.document_request_reminders', null, null, null, $report);
$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);
