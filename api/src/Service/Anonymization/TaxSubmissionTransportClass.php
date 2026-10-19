<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

enum TaxSubmissionTransportClass: string
{
    case NO_TRANSPORT = 'no_transport';
    case CANCELLED_BEFORE_DISPATCH = 'cancelled_before_dispatch';
    case SOURCE_PROVENANCE = 'source_provenance';
}
