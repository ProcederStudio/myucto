<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\PostingService;

final class LegacyFxNetFixture
{
    public static function install(int $supplierId, int $periodId): array
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $capture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Accounting/fx_legacy_net_500.json'), true, 512, JSON_THROW_ON_ERROR);
        $payload = $capture['payload'];
        $stmt = $pdo->prepare('SELECT id,varsymbol,exchange_rate FROM invoices WHERE supplier_id=? ORDER BY id');
        $stmt->execute([$supplierId]);
        $documents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (count($documents) !== 2) throw new \LogicException('Legacy fixture requires its two native invoices.');
        foreach ($payload['detail'] as $index => &$detail) {
            if (bccomp((string) $documents[$index]['exchange_rate'], (string) $detail['fx_rate'], 6) !== 0) throw new \LogicException('Legacy invoice rate differs from capture.');
            $detail['doc_id'] = (int) $documents[$index]['id'];
            $detail['varsymbol'] = $documents[$index]['varsymbol'];
        }
        unset($detail);
        $lines = [];
        foreach ($capture['lines'] as $line) {
            $lines[] = array_intersect_key($line, array_flip(['account_code', 'side', 'amount', 'is_red_storno', 'currency_code', 'fx_rate', 'amount_foreign', 'cost_center', 'project_id']));
        }
        $entry = $container->get(PostingService::class)->postDocument($supplierId, 'fx_revaluation', ClosingSourceId::fxSaldo($periodId), $lines, ['entry_date' => '2098-12-31', 'posted' => true, 'allow_closing_period' => true]);
        $stmt = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=?');
        foreach ($payload['saldo_lines'] as &$line) {
            $stmt->execute([$supplierId, $line['account_code']]);
            $line['account_id'] = (int) $stmt->fetchColumn();
        }
        unset($line);
        $payload['entry_ids']['saldo'] = $entry;
        $container->get(ClosingRepository::class)->upsertStep($supplierId, $periodId, 'fx_revaluation', 'done', $payload, null, null);
        return ['entry_ids' => ['saldo' => $entry], 'totals' => ['loss' => 0.0, 'gain' => 500.0]];
    }
}
