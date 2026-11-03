<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

/** One cbc:TaxSubtotal group: taxable base and tax for a category/rate pair. */
final readonly class TaxSubtotal
{
    public function __construct(
        public TaxCategory $category,
        public int $rateBasisPoints,
        public Money $taxableAmount,
        public Money $taxAmount,
    ) {
    }

    public function key(): string
    {
        return $this->category->value.':'.$this->rateBasisPoints;
    }
}
