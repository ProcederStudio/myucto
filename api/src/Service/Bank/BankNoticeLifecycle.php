<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/** Životní stav avíza nezávislý na párování a na existenci pozdějšího výpisu. */
final class BankNoticeLifecycle
{
    public const SUPERSEDED = 'superseded';

    /**
     * Převzetí úhrady nechává legacy avízo `unmatched`; tento marker ale trvale
     * brání jeho novému započtení nebo použití jako mzdového platebního signálu.
     * Oficiální výpis není provizorní, proto ho pravidlo nikdy nevyloučí.
     *
     * Marker platí jen, dokud avízo zůstává nespárované. Když ho uživatel po smazání
     * výpisu ručně spáruje, nese úhradu zase ono a do peněžního toku patří.
     */
    public static function activeSql(string $transactionAlias): string
    {
        return "NOT ({$transactionAlias}.source IN ('email_notice','idoklad')"
            . " AND COALESCE({$transactionAlias}.ignore_origin, '') = '" . self::SUPERSEDED . "'"
            . " AND {$transactionAlias}.match_status IN ('unmatched','ignored'))";
    }
}
