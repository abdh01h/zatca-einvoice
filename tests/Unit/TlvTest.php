<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Exception\InvalidArgumentException;
use Zatca\EInvoice\Qr\Tlv;

final class TlvTest extends TestCase
{
    public function test_it_encodes_tag_length_value_triplets(): void
    {
        $encoded = Tlv::encode([1 => 'Acme', 2 => '311111111101113']);
        $decoded = base64_decode($encoded, true);

        self::assertSame("\x01\x04Acme", substr((string) $decoded, 0, 6));
        self::assertSame("\x02\x0F311111111101113", substr((string) $decoded, 6));
    }

    public function test_it_round_trips_utf8_values(): void
    {
        $fields = [1 => 'شركة أكمي', 2 => '311111111101113', 3 => '2026-11-03T17:41:08Z'];

        self::assertSame($fields, Tlv::decode(Tlv::encode($fields)));
    }

    public function test_it_sorts_tags_and_rejects_oversized_values(): void
    {
        self::assertSame([1 => 'a', 2 => 'b'], Tlv::decode(Tlv::encode([2 => 'b', 1 => 'a'])));

        $this->expectException(InvalidArgumentException::class);
        Tlv::encode([1 => str_repeat('x', 256)]);
    }

    public function test_it_rejects_a_truncated_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Tlv::decode(base64_encode("\x01\x05abc"));
    }
}
