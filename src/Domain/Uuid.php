<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * RFC 4122 version 4 UUID, the identifier type ZATCA requires for
 * cbc:UUID on invoices and credit/debit notes.
 */
final readonly class Uuid implements \Stringable
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid UUID.', $value));
        }
    }

    public static function v4(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
    }

    public static function fromString(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->value, $other->value) === 0;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
