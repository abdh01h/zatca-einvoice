<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Domain\Quantity;
use Zatca\EInvoice\Exception\InvalidArgumentException;

final class QuantityTest extends TestCase
{
    public function test_it_keeps_three_fraction_digits_internally(): void
    {
        $quantity = Quantity::of('1.5', 'KGM');

        self::assertSame(1500, $quantity->milliUnits);
        self::assertSame('1.5', $quantity->toDecimal());
        self::assertSame('KGM', $quantity->unitCode);
    }

    public function test_it_trims_trailing_zeros(): void
    {
        self::assertSame('2', Quantity::of(2)->toDecimal());
        self::assertSame('2.25', Quantity::of('2.250')->toDecimal());
        self::assertSame('0.125', Quantity::of(0.125)->toDecimal());
    }

    public function test_it_rejects_four_fraction_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::of('1.2345');
    }

    public function test_it_rejects_an_empty_unit_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Quantity(1000, '');
    }
}
