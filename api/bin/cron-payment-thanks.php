<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Mail\{Mailer, PaymentThanksMailer};

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
$run = CronRun::start($pdo, 'cron-payment-thanks');
try {
    $stats = $container->get(PaymentThanksMailer::class)->flushDeferred();
    $mail = $container->get(Mailer::class)->flushDeferred();
    $failed = $stats['failed'] + $mail['failed'];
    $run->finish($failed > 0 ? 'error' : 'ok', ['payment_thanks' => $stats, 'mail' => $mail]);
    echo json_encode(['payment_thanks' => $stats, 'mail' => $mail], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($failed > 0 ? 1 : 0);
} catch (Throwable) {
    $run->finish('error', ['error' => 'payment_thanks_worker_failed']);
    fwrite(STDERR, "Payment thanks worker failed.\n");
    exit(1);
}
