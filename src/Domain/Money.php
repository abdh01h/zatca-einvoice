<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * An exact monetary amount held as an integer number of minor units.
 *
 * No float ever touches an amount: `19.99` is stored as `1999` and every
 * operation rounds once, explicitly, with {@see Rounding::halfUp()}.
 */
final readonly class Money implements \Stringable
{
    public const MINOR_UNITS_PER_UNIT = 100;

    public function __construct(
        public int $minorUnits,
        public Currency $currency = Currency::SAR,
    ) {
    }

    public static function zero(Currency $currency = Currency::SAR): self
    {
        return new self(0, $currency);
    }

    public static function of(int $minorUnits, Currency $currency = Currency::SAR): self
    {
        return new self($minorUnits, $currency);
    }

    /**
     * Parse a decimal string such as "1,299.00" is NOT accepted -- exactly
     * "-?\d+(\.\d{1,2})?" is, so callers can never silently lose precision.
     */
    public static function fromDecimal(int|string $amount, Currency $currency = Currency::SAR): self
    {
        if (is_int($amount)) {
            return new self($amount * self::MINOR_UNITS_PER_UNIT, $currency);
        }

        $normalized = trim($amount);

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $normalized, $m) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Amount "%s" is not a valid decimal with at most two fraction digits.',
                $amount,
            ));
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $units = (int) $m[2];
        $fraction = isset($m[3]) ? (int) str_pad($m[3], 2, '0') : 0;

        return new self($sign * ($units * self::MINOR_UNITS_PER_UNIT + $fraction), $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    /** Multiply by a quantity expressed in thousandths of a unit (see Quantity). */
    public function timesQuantity(Quantity $quantity): self
    {
        return new self(
            Rounding::halfUp($this->minorUnits * $quantity->milliUnits, Quantity::MILLI_UNITS_PER_UNIT),
            $this->currency,
        );
    }

    /** Apply a rate expressed in basis points (1500 = 15.00%). */
    public function percentage(int $basisPoints): self
    {
        return new self(Rounding::halfUp($this->minorUnits * $basisPoints, 10_000), $this->currency);
    }

    /** Fixed-point decimal with exactly two fraction digits, e.g. "19.99". */
    public function toDecimal(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, self::MINOR_UNITS_PER_UNIT), $absolute % self::MINOR_UNITS_PER_UNIT);
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s.',
                $this->currency->value,
                $other->currency->value,
            ));
        }
    }
}
