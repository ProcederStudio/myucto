<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Nová verze registrace účtárny nenavazuje za poslední uloženou. Totéž pravidlo
 * hlídá trigger trg_payroll_office_registration_no_backdate; repository ho ověří
 * předem, aby očekávané odmítnutí nekončilo v logu jako chyba databáze.
 */
final class PayrollOfficeRegistrationConflictException extends \RuntimeException
{
    public function __construct(public readonly string $latestEffectiveFrom)
    {
        parent::__construct('Datum účinnosti musí navazovat za poslední uloženou verzi.');
    }
}
