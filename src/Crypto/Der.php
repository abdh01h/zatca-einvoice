<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * A minimal DER encoder: just enough ASN.1 to build a CSR by hand.
 *
 * It exists because ZATCA's subject carries `serialNumber = 1-...|2-...|3-...`
 * and OpenSSL types that attribute as PrintableString, which forbids `|` --
 * both openssl_csr_new() and the `openssl req` CLI refuse it. Encoding the
 * CertificationRequestInfo directly is the only way to produce the profile
 * without pulling in a full ASN.1 library.
 */
final class Der
{
    public static function sequence(string ...$parts): string
    {
        return self::tlv(0x30, implode('', $parts));
    }

    public static function set(string ...$parts): string
    {
        return self::tlv(0x31, implode('', $parts));
    }

    public static function utf8String(string $value): string
    {
        return self::tlv(0x0c, $value);
    }

    public static function printableString(string $value): string
    {
        return self::tlv(0x13, $value);
    }

    public static function integer(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Only non-negative integers are encoded here.');
        }

        $bytes = ltrim(pack('N', $value), "\x00");

        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return self::tlv(0x02, $bytes);
    }

    public static function octetString(string $bytes): string
    {
        return self::tlv(0x04, $bytes);
    }

    public static function bitString(string $bytes): string
    {
        return self::tlv(0x03, "\x00".$bytes);
    }

    /** Context-specific constructed tag, used for [0] attributes. */
    public static function context(int $number, string $content): string
    {
        return self::tlv(0xa0 | $number, $content);
    }

    public static function oid(string $dotted): string
    {
        $arcs = array_map('intval', explode('.', $dotted));

        if (count($arcs) < 2) {
            throw new InvalidArgumentException(sprintf('"%s" is not an object identifier.', $dotted));
        }

        $body = self::byte(40 * $arcs[0] + $arcs[1]);

        for ($i = 2, $count = count($arcs); $i < $count; $i++) {
            $value = $arcs[$i];
            $chunk = [$value & 0x7f];
            $value >>= 7;

            while ($value > 0) {
                $chunk[] = 0x80 | ($value & 0x7f);
                $value >>= 7;
            }

            foreach (array_reverse($chunk) as $byte) {
                $body .= self::byte($byte);
            }
        }

        return self::tlv(0x06, $body);
    }

    public static function tlv(int $tag, string $content): string
    {
        return self::byte($tag).self::length(strlen($content)).$content;
    }

    private static function byte(int $value): string
    {
        if ($value < 0 || $value > 255) {
            throw new InvalidArgumentException(sprintf('%d does not fit in one byte.', $value));
        }

        return chr($value);
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return self::byte($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return self::byte(0x80 | strlen($bytes)).$bytes;
    }
}
