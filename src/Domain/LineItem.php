<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * One invoice line.
 *
 * The line's own arithmetic lives here so that the XML builder and the
 * validator cannot drift apart: both read `taxableAmount()` and `taxAmount()`.
 */
final readonly class LineItem
{
    public function __construct(
        public string $name,
        public Quantity $quantity,
        public Money $unitPrice,
        public TaxCategory $taxCategory = TaxCategory::Standard,
        public ?string $sellerItemId = null,
        public ?string $note = null,
        public ?Money $discount = null,
        public ?string $discountReason = null,
        public ?int $taxRateBasisPoints = null,
        public ?string $exemptionReasonCode = null,
        public ?string $exemptionReason = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A line item needs a name.');
        }

        if ($quantity->isZero() || $quantity->isNegative()) {
            throw new InvalidArgumentException(sprintf('Line "%s" needs a positive quantity.', $name));
        }

        if ($unitPrice->isNegative()) {
            throw new InvalidArgumentException(sprintf('Line "%s" cannot have a negative unit price; use a credit note.', $name));
        }

        if ($discount !== null && ($discount->isNegative() || $discount->minorUnits > $this->extensionAmount()->minorUnits)) {
            throw new InvalidArgumentException(sprintf('Line "%s" has a discount outside 0..line extension.', $name));
        }

        if ($discount !== null && $discount->currency !== $unitPrice->currency) {
            throw new InvalidArgumentException('Discount and unit price must share a currency.');
        }

        if ($taxCategory->requiresExemptionReason() && $exemptionReasonCode === null) {
            throw new InvalidArgumentException(sprintf(
                'Line "%s" uses tax category %s and therefore needs an exemption reason code (VATEX-SA-*).',
                $name,
                $taxCategory->value,
            ));
        }
    }

    /** Quantity x unit price, before any line-level discount. */
    public function extensionAmount(): Money
    {
        return $this->unitPrice->timesQuantity($this->quantity);
    }

    public function allowanceAmount(): Money
    {
        return $this->discount ?? Money::zero($this->unitPrice->currency);
    }

    /** Net amount the tax is computed on. */
    public function taxableAmount(): Money
    {
        return $this->extensionAmount()->minus($this->allowanceAmount());
    }

    public function taxRateBasisPoints(): int
    {
        return $this->taxRateBasisPoints ?? $this->taxCategory->defaultRateBasisPoints();
    }

    public function taxAmount(): Money
    {
        return $this->taxableAmount()->percentage($this->taxRateBasisPoints());
    }

    public function totalAmount(): Money
    {
        return $this->taxableAmount()->plus($this->taxAmount());
    }
}
