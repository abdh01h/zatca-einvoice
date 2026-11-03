<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * A document-level allowance (discount) or charge (freight, handling...).
 *
 * Both are taxed at the category and rate carried here, because ZATCA
 * reconciles TaxExclusiveAmount against the sum of line nets plus these
 * adjustments.
 */
final readonly class AllowanceCharge
{
    public function __construct(
        public Money $amount,
        public bool $isCharge = false,
        public string $reason = 'Discount',
        public TaxCategory $taxCategory = TaxCategory::Standard,
        public ?int $taxRateBasisPoints = null,
        public ?string $exemptionReasonCode = null,
    ) {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('An allowance or charge amount must not be negative.');
        }

        if ($taxCategory->requiresExemptionReason() && $exemptionReasonCode === null) {
            throw new InvalidArgumentException(sprintf(
                'An allowance/charge using tax category %s needs an exemption reason code (VATEX-SA-*).',
                $taxCategory->value,
            ));
        }
    }

    public function taxRateBasisPoints(): int
    {
        return $this->taxRateBasisPoints ?? $this->taxCategory->defaultRateBasisPoints();
    }

    public function taxAmount(): Money
    {
        return $this->amount->percentage($this->taxRateBasisPoints());
    }

    /** Signed effect on the taxable base: allowances subtract, charges add. */
    public function signedAmount(): Money
    {
        return $this->isCharge ? $this->amount : $this->amount->negated();
    }

    public function signedTax(): Money
    {
        return $this->isCharge ? $this->taxAmount() : $this->taxAmount()->negated();
    }
}
