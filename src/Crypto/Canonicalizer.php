<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Exception\SigningException;

/**
 * XML canonicalisation in the form ZATCA expects.
 *
 * ZATCA's digest and signature transforms are C14N 1.1 (non-exclusive). PHP's
 * DOM only exposes C14N 1.0 and exclusive C14N, so non-exclusive 1.0 is used
 * here: for documents this library generates (no xml:base, no relative
 * namespace weirdness) the two produce identical octets, and every digest is
 * computed from the same serialiser, so signing and verification agree. This
 * trade-off is documented in docs/COMPLIANCE.md.
 */
final class Canonicalizer
{
    public static function canonicalize(\DOMNode $node): string
    {
        $canonical = $node->C14N(false, false);

        if ($canonical === false) {
            throw new SigningException('Unable to canonicalise XML node.');
        }

        return $canonical;
    }

    public static function sha256(string $data): string
    {
        return hash('sha256', $data, true);
    }
}
