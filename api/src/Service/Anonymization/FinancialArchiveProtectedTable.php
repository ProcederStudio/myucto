<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

enum FinancialArchiveProtectedTable: string
{
    case SNAPSHOT = 'income_tax_return_snapshots';
    case TAX_RETURN = 'income_tax_returns';
    case TAX_SUBMISSION = 'tax_submissions';
    case LEGAL_PROFILE = 'nonprofit_legal_profiles';
    case SAVING_SOURCE = 'nonprofit_tax_saving_sources';
    case SAVING_COMMAND = 'nonprofit_tax_saving_commands';
    case SAVING_EVENT = 'nonprofit_tax_saving_events';
}
