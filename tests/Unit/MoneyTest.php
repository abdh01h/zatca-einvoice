<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Domain\Currency;
use Zatca\EInvoice\Domain\Money;
use Zatca\EInvoice\Domain\Quantity;
use Zatca\EInvoice\Exception\InvalidArgumentException;

final class MoneyTest extends TestCase
{
    #[DataProvider('decimals')]
    public function test_it_parses_decimal_strings_exactly(string $input, int $expectedMinorUnits, string $expectedOutput): void
    {
        $money = Money::fromDecimal($input);

        self::assertSame($expectedMinorUnits, $money->minorUnits);
        self::assertSame($expectedOutput, $money->toDecimal());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function decimals(): iterable
    {
        yield 'integer' => ['12', 1200, '12.00'];
        yield 'two decimals' => ['1299.00', 129900, '1299.00'];
        yield 'one decimal' => ['0.5', 50, '0.50'];
        yield 'zero' => ['0.00', 0, '0.00'];
        yield 'negative' => ['-3.25', -325, '-3.25'];
        yield 'large' => ['1234567.89', 123456789, '1234567.89'];
    }

    public function test_it_rejects_more_than_two_fraction_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('10.005');
    }

    public function test_it_rejects_thousands_separators(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('1,299.00');
    }

    public function test_it_adds_and_subtracts(): void
    {
        $a = Money::fromDecimal('10.10');
        $b = Money::fromDecimal('2.05');

        self::assertSame('12.15', $a->plus($b)->toDecimal());
        self::assertSame('8.05', $a->minus($b)->toDecimal());
    }

    public function test_it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100)->plus(Money::of(100, Currency::USD));
    }

    public function test_it_multiplies_by_a_fractional_quantity_with_half_up_rounding(): void
    {
        $price = Money::fromDecimal('3.33');

        self::assertSame('4.16', $price->timesQuantity(Quantity::of('1.25'))->toDecimal());
    }

    public function test_percentage_rounds_half_up(): void
    {
        // half a halala rounds away from zero, not to even
        self::assertSame('0.01', Money::of(5)->percentage(1000)->toDecimal());
        self::assertSame('15.00', Money::fromDecimal('100.00')->percentage(1500)->toDecimal());
        self::assertSame('0.60', Money::fromDecimal('4.00')->percentage(1500)->toDecimal());
    }

    public function test_string_casting_is_the_decimal_form(): void
    {
        self::assertSame('19.99', (string) Money::fromDecimal('19.99'));
    }
}
