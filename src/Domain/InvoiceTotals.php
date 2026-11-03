<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

/**
 * The monetary summary ZATCA reconciles, in the exact shape UBL needs.
 */
final readonly class InvoiceTotals
{
    /** @param list<TaxSubtotal> $taxSubtotals */
    public function __construct(
        public Money $lineExtensionAmount,
        public Money $allowanceTotalAmount,
        public Money $chargeTotalAmount,
        public Money $taxExclusiveAmount,
        public Money $taxAmount,
        public Money $taxInclusiveAmount,
        public Money $prepaidAmount,
        public Money $payableAmount,
        public array $taxSubtotals,
    ) {
    }
}
