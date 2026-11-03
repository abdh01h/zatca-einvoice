<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * An exchange rate held as an integer number of millionths.
 *
 * ZATCA's tax currency is SAR, so a foreign-currency invoice carries a second
 * cac:TaxTotal in SAR; the rate that produces it has to be exact rather than a
 * float, for the same reason amounts are: the number ends up in a signed,
 * hashed document.
 */
final readonly class ExchangeRate implements \Stringable
{
    public const MICRO = 1_000_000;

    public function __construct(public int $microRate)
    {
        if ($microRate <= 0) {
            throw new InvalidArgumentException('An exchange rate must be greater than zero.');
        }
    }

    public static function fromDecimal(string|int $rate): self
    {
        if (is_int($rate)) {
            return new self($rate * self::MICRO);
        }

        $normalized = trim($rate);

        if (preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid exchange rate with at most six decimals.', $rate));
        }

        $fraction = isset($matches[2]) ? (int) str_pad($matches[2], 6, '0') : 0;

        return new self((int) $matches[1] * self::MICRO + $fraction);
    }

    /** Applies the rate to an amount, rounding once, half up. */
    public function convert(Money $amount): Money
    {
        return new Money(
            Rounding::halfUp($amount->minorUnits * $this->microRate, self::MICRO),
            $amount->currency,
        );
    }

    public function toDecimal(): string
    {
        $units = intdiv($this->microRate, self::MICRO);
        $fraction = $this->microRate % self::MICRO;

        if ($fraction === 0) {
            return $units.'.00';
        }

        return rtrim(rtrim(sprintf('%d.%06d', $units, $fraction), '0'), '.') === (string) $units
            ? $units.'.00'
            : rtrim(rtrim(sprintf('%d.%06d', $units, $fraction), '0'), '.');
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }
}
