<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * A Saudi VAT registration number.
 *
 * ZATCA mandates exactly 15 digits, beginning and ending with 3
 * (Business Rule BR-KSA-39 in the XML Implementation Standard).
 */
final readonly class VatNumber implements \Stringable
{
    private const PATTERN = '/^3\d{13}3$/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a valid Saudi VAT number: expected 15 digits starting and ending with 3.',
                $value,
            ));
        }
    }

    public static function tryFrom(string $value): ?self
    {
        return preg_match(self::PATTERN, trim($value)) === 1 ? new self(trim($value)) : null;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
