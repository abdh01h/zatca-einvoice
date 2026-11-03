<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * The SHA-256 digest of an invoice, in the three encodings ZATCA asks for.
 *
 * ZATCA is inconsistent here, and getting it wrong is the single most common
 * integration failure:
 *
 *  - ds:DigestValue and QR tag 6 carry base64 of the *raw* 32-byte digest.
 *  - PIH (the chain to the previous invoice) carries base64 of the *lowercase
 *    hex* digest -- an 88-character string. The first invoice in a chain uses
 *    base64(hex(sha256("0"))).
 */
final readonly class InvoiceHash implements \Stringable
{
    private function __construct(public string $raw)
    {
        if (strlen($raw) !== 32) {
            throw new InvalidArgumentException('An invoice hash is exactly 32 raw bytes.');
        }
    }

    public static function fromRaw(string $raw): self
    {
        return new self($raw);
    }

    public static function fromHex(string $hex): self
    {
        $raw = hex2bin($hex);

        if ($raw === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a hex digest.', $hex));
        }

        return new self($raw);
    }

    /** Accepts base64 of the raw digest, as found in ds:DigestValue. */
    public static function fromBase64(string $base64): self
    {
        $raw = base64_decode($raw = trim($base64), true);

        if ($raw === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not valid base64.', $base64));
        }

        return new self($raw);
    }

    /** Accepts base64 of the hex digest, as found in PIH. */
    public static function fromBase64OfHex(string $base64): self
    {
        $hex = base64_decode(trim($base64), true);

        if ($hex === false) {
            throw new InvalidArgumentException('Invalid base64 in PIH value.');
        }

        return self::fromHex($hex);
    }

    /** The value ZATCA expects on the very first invoice of a chain. */
    public static function initialPreviousHash(): string
    {
        return self::fromHex(hash('sha256', '0'))->base64OfHex();
    }

    public function hex(): string
    {
        return bin2hex($this->raw);
    }

    /** base64 of the raw digest: ds:DigestValue, QR tag 6. */
    public function base64(): string
    {
        return base64_encode($this->raw);
    }

    /** base64 of the hex digest: the PIH encoding. */
    public function base64OfHex(): string
    {
        return base64_encode($this->hex());
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->raw, $other->raw);
    }

    public function __toString(): string
    {
        return $this->base64OfHex();
    }
}
