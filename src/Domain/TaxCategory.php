<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

/**
 * UN/CEFACT 5305 tax category codes as restricted by ZATCA.
 *
 * S = standard rate, Z = zero rated, E = exempt, O = out of scope.
 * ZATCA requires an exemption reason code on every line whose category is
 * Z, E or O (cbc:TaxExemptionReasonCode, VATEX-SA-* list).
 */
enum TaxCategory: string
{
    case Standard = 'S';
    case ZeroRated = 'Z';
    case Exempt = 'E';
    case OutOfScope = 'O';

    /** ZATCA's standard VAT rate in basis points (15.00%). */
    public const STANDARD_RATE_BASIS_POINTS = 1500;

    public function defaultRateBasisPoints(): int
    {
        return $this === self::Standard ? self::STANDARD_RATE_BASIS_POINTS : 0;
    }

    public function requiresExemptionReason(): bool
    {
        return $this !== self::Standard;
    }
}
