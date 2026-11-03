<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\ZatcaException;

/**
 * Computes invoice totals with a single rounding point per line.
 *
 * Tax is rounded per line and then summed; ZATCA accepts line-level rounding
 * (its SDK does the same) and it keeps every line's own tax figure equal to
 * the one printed on the customer's copy.
 */
final class InvoiceCalculator
{
    public static function totals(Invoice $invoice): InvoiceTotals
    {
        $currency = $invoice->currency;
        $zero = Money::zero($currency);

        $lineExtension = $zero;
        $tax = $zero;
        /** @var array<string, TaxSubtotal> $groups */
        $groups = [];

        foreach ($invoice->lines as $line) {
            $lineExtension = $lineExtension->plus($line->extensionAmount());
            $lineTax = $line->taxAmount();
            $tax = $tax->plus($lineTax);

            $key = $line->taxCategory->value.':'.$line->taxRateBasisPoints();
            $previous = $groups[$key] ?? new TaxSubtotal(
                $line->taxCategory,
                $line->taxRateBasisPoints(),
                $zero,
                $zero,
            );

            $groups[$key] = new TaxSubtotal(
                $previous->category,
                $previous->rateBasisPoints,
                $previous->taxableAmount->plus($line->taxableAmount()),
                $previous->taxAmount->plus($lineTax),
            );
        }

        $allowanceTotal = $zero;
        $chargeTotal = $zero;
        $netAdjustment = $zero;

        foreach ($invoice->adjustments as $adjustment) {
            if ($adjustment->isCharge) {
                $chargeTotal = $chargeTotal->plus($adjustment->amount);
            } else {
                $allowanceTotal = $allowanceTotal->plus($adjustment->amount);
            }

            $tax = $tax->plus($adjustment->signedTax());
            $netAdjustment = $netAdjustment->plus($adjustment->signedAmount());

            $key = $adjustment->taxCategory->value.':'.$adjustment->taxRateBasisPoints();
            $previous = $groups[$key] ?? new TaxSubtotal(
                $adjustment->taxCategory,
                $adjustment->taxRateBasisPoints(),
                $zero,
                $zero,
            );

            $groups[$key] = new TaxSubtotal(
                $previous->category,
                $previous->rateBasisPoints,
                $previous->taxableAmount->plus($adjustment->signedAmount()),
                $previous->taxAmount->plus($adjustment->signedTax()),
            );
        }

        // cbc:LineExtensionAmount is the sum of line nets; allowances and
        // charges are then applied to reach the tax-exclusive amount.
        $taxExclusive = $lineExtension->plus($netAdjustment);
        $taxInclusive = $taxExclusive->plus($tax);
        $prepaid = $invoice->prepaidAmount ?? $zero;
        $payable = $taxInclusive->minus($prepaid);

        $groups = array_filter($groups, static fn (TaxSubtotal $g): bool => ! $g->taxableAmount->isZero() || ! $g->taxAmount->isZero());
        ksort($groups);

        self::assertInvariants($taxExclusive, $tax, $taxInclusive, $payable);

        return new InvoiceTotals(
            lineExtensionAmount: $lineExtension,
            allowanceTotalAmount: $allowanceTotal,
            chargeTotalAmount: $chargeTotal,
            taxExclusiveAmount: $taxExclusive,
            taxAmount: $tax,
            taxInclusiveAmount: $taxInclusive,
            prepaidAmount: $prepaid,
            payableAmount: $payable,
            taxSubtotals: array_values($groups),
        );
    }

    private static function assertInvariants(Money $taxExclusive, Money $tax, Money $taxInclusive, Money $payable): void
    {
        if (! $taxExclusive->plus($tax)->equals($taxInclusive)) {
            throw new ZatcaException('Totals invariant broken: tax-exclusive + tax != tax-inclusive.');
        }

        if ($payable->minorUnits > $taxInclusive->minorUnits) {
            throw new ZatcaException('Totals invariant broken: payable exceeds tax-inclusive amount.');
        }
    }
}
