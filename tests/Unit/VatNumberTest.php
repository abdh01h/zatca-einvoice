<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Domain\VatNumber;
use Zatca\EInvoice\Exception\InvalidArgumentException;

final class VatNumberTest extends TestCase
{
    public function test_it_accepts_a_well_formed_number(): void
    {
        self::assertSame('311111111101113', (string) new VatNumber('311111111101113'));
    }

    #[DataProvider('invalidNumbers')]
    public function test_it_rejects_malformed_numbers(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VatNumber($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield 'too short' => ['31111111110111'];
        yield 'too long' => ['3111111111011130'];
        yield 'does not start with 3' => ['211111111101113'];
        yield 'does not end with 3' => ['311111111101114'];
        yield 'letters' => ['31111111110111X'];
        yield 'empty' => [''];
    }

    public function test_try_from_returns_null_instead_of_throwing(): void
    {
        self::assertNull(VatNumber::tryFrom('nope'));
        self::assertInstanceOf(VatNumber::class, VatNumber::tryFrom(' 311111111101113 '));
    }
}
