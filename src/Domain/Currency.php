<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

/**
 * Currencies permitted on a ZATCA e-invoice.
 *
 * The tax currency is always SAR; the invoice currency may differ only for
 * exports, in which case ZATCA requires the tax amounts to also be expressed
 * in SAR (see docs/COMPLIANCE.md).
 */
enum Currency: string
{
    case SAR = 'SAR';
    case USD = 'USD';
    case EUR = 'EUR';
    case AED = 'AED';
    case GBP = 'GBP';

    public function isTaxCurrency(): bool
    {
        return $this === self::SAR;
    }
}
