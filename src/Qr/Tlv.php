<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Qr;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * Tag-Length-Value encoding used by ZATCA QR codes.
 *
 * Each entry is one byte of tag, one byte of length, then that many UTF-8
 * bytes. A value of 256 bytes or more cannot be encoded, which is why the
 * 88-character PIH-style base64 strings are never placed in a QR code.
 */
final class Tlv
{
    /**
     * @param array<int, string> $fields tag => value
     */
    public static function encode(array $fields): string
    {
        ksort($fields);
        $payload = '';

        foreach ($fields as $tag => $value) {
            if ($tag < 1 || $tag > 255) {
                throw new InvalidArgumentException(sprintf('QR tag %d is outside 1..255.', $tag));
            }

            $length = strlen($value);

            if ($length > 255) {
                throw new InvalidArgumentException(sprintf('QR tag %d is %d bytes; the limit is 255.', $tag, $length));
            }

            $payload .= chr($tag).chr($length).$value;
        }

        return base64_encode($payload);
    }

    /** @return array<int, string> tag => value */
    public static function decode(string $base64): array
    {
        $payload = base64_decode(trim($base64), true);

        if ($payload === false) {
            throw new InvalidArgumentException('QR payload is not valid base64.');
        }

        $fields = [];
        $offset = 0;
        $total = strlen($payload);

        while ($offset < $total) {
            if ($offset + 2 > $total) {
                throw new InvalidArgumentException('Truncated TLV header.');
            }

            $tag = ord($payload[$offset]);
            $length = ord($payload[$offset + 1]);

            if ($offset + 2 + $length > $total) {
                throw new InvalidArgumentException(sprintf('TLV tag %d claims %d bytes past the end of the payload.', $tag, $length));
            }

            $fields[$tag] = substr($payload, $offset + 2, $length);
            $offset += 2 + $length;
        }

        return $fields;
    }
}
