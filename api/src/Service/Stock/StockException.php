<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

/**
 * Skladovou operaci nelze provést (nedostatek zásob, zamčené období, otevřená
 * inventura, přeplnění příjmu…). Nese strojový kód pro i18n + HTTP status a
 * volitelný strukturovaný detail (`$details`) — zejména výčet chybějících položek
 * u `insufficient_stock` (A3): [{stock_item_id, sku, name, requested, available,
 * warehouse_id, warehouse_name, unit}] ({@see StockShortageDetails}).
 *
 * Stejný vzor jako Cash\CashException / Closing\ClosingException — navazující
 * Action ji přeloží na Json::error bez ztráty kontextu.
 */
final class StockException extends \RuntimeException
{
    /**
     * @param array<int,array<string,mixed>>|array<string,mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
