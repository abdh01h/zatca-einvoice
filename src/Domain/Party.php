<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * A seller or buyer.
 *
 * `vatNumber` is mandatory for the seller and for the buyer of a standard
 * (B2B) invoice; it stays null for the buyer of a simplified (B2C) invoice.
 */
final readonly class Party
{
    public function __construct(
        public string $registrationName,
        public ?Address $address = null,
        public ?VatNumber $vatNumber = null,
        public ?string $commercialRegistrationNumber = null,
    ) {
        if (trim($registrationName) === '') {
            throw new InvalidArgumentException('A party registration name is required.');
        }
    }

    public function withVatNumber(VatNumber $vatNumber): self
    {
        return new self($this->registrationName, $this->address, $vatNumber, $this->commercialRegistrationNumber);
    }
}
