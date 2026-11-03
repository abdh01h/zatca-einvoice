<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * Deterministic, integer-only rounding.
 *
 * Floating point is never used for money in this library: every amount is an
 * integer number of minor units (halalas) and every rate an integer number of
 * basis points. That makes totals reproducible exactly, which matters because
 * ZATCA recomputes the invoice hash over the document we send.
 */
final class Rounding
{
    /** Round half away from zero -- the convention used by ZATCA's own SDK. */
    public static function halfUp(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Cannot divide by zero.');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $n = abs($numerator);
        $d = abs($denominator);

        $quotient = intdiv($n, $d);
        $remainder = $n % $d;

        if ($remainder * 2 >= $d) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }
}
