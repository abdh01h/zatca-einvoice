<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * A quantity held in thousandths of a unit, so line maths stay in integers.
 *
 * 1.5 kg is `1500`; formatting back to text drops trailing zeros ("1.5", "2").
 */
final readonly class Quantity implements \Stringable
{
    public const MILLI_UNITS_PER_UNIT = 1000;

    public function __construct(
        public int $milliUnits,
        public string $unitCode = 'PCE',
    ) {
        if ($unitCode === '') {
            throw new InvalidArgumentException('A UN/CEFACT unit code is required.');
        }
    }

    public static function of(int|string|float $value, string $unitCode = 'PCE'): self
    {
        if (is_int($value)) {
            return new self($value * self::MILLI_UNITS_PER_UNIT, $unitCode);
        }

        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
        }

        $normalized = trim($value);

        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $normalized, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Quantity "%s" is not a valid decimal with at most three fraction digits.', $normalized));
        }

        $fraction = isset($m[2]) ? (int) str_pad($m[2], 3, '0') : 0;

        return new self((int) $m[1] * self::MILLI_UNITS_PER_UNIT + $fraction, $unitCode);
    }

    public function isZero(): bool
    {
        return $this->milliUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->milliUnits < 0;
    }

    public function toDecimal(): string
    {
        $units = intdiv($this->milliUnits, self::MILLI_UNITS_PER_UNIT);
        $fraction = $this->milliUnits % self::MILLI_UNITS_PER_UNIT;

        if ($fraction === 0) {
            return (string) $units;
        }

        return rtrim(sprintf('%d.%03d', $units, $fraction), '0');
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }
}
