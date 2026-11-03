<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * Minimal arbitrary-precision conversion, so the library does not need
 * ext-bcmath or ext-gmp just to print a certificate serial number.
 */
final class Decimal
{
    public static function fromHex(string $hex): string
    {
        $hex = strtolower(ltrim($hex, '0'));

        if ($hex === '') {
            return '0';
        }

        if (preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a hexadecimal number.', $hex));
        }

        $decimal = '0';

        foreach (str_split($hex) as $digit) {
            $decimal = self::multiply($decimal, 16);
            $decimal = self::add($decimal, (string) hexdec($digit));
        }

        return $decimal;
    }

    private static function multiply(string $decimal, int $factor): string
    {
        $carry = 0;
        $result = '';

        for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
            $product = ((int) $decimal[$i]) * $factor + $carry;
            $result = ($product % 10).$result;
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = ($carry % 10).$result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') === '' ? '0' : ltrim($result, '0');
    }

    private static function add(string $left, string $right): string
    {
        $left = str_pad($left, strlen($right), '0', STR_PAD_LEFT);
        $right = str_pad($right, strlen($left), '0', STR_PAD_LEFT);

        $carry = 0;
        $result = '';

        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            $sum = ((int) $left[$i]) + ((int) $right[$i]) + $carry;
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return $carry > 0 ? '1'.$result : ltrim($result, '0');
    }
}
