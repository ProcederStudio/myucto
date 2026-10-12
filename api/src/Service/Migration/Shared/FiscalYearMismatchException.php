<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Účetní rok zdroje neodpovídá účetnímu roku firmy (nastavení „Účetní rok začíná").
 *
 * Převod se ZASTAVÍ: zařadit data hospodářského roku do kalendářních období (nebo
 * obráceně) by rozdělilo jeden účetní rok zdroje do dvou období firmy — uzávěrka,
 * výsledek hospodaření i přiznání k dani by pak počítaly z jiných dat než zdroj.
 */
final class FiscalYearMismatchException extends \DomainException
{
    public function __construct(
        string $message,
        public readonly int $suggestedStartMonth,
    ) {
        parent::__construct($message);
    }
}
