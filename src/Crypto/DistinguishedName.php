<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

/**
 * Renders an OpenSSL DN array the way OpenSSL prints it: most specific RDN
 * first, comma-space separated ("CN=..., OU=..., O=..., C=...").
 *
 * ZATCA compares ds:X509IssuerName against the issuer of the signing
 * certificate character by character, so this must match OpenSSL's own
 * ordering rather than the certificate's stored order.
 */
final class DistinguishedName
{
    /** @param array<string, string|array<int, string>> $attributes */
    public static function render(array $attributes): string
    {
        $parts = [];

        foreach (array_reverse($attributes, true) as $key => $values) {
            foreach ((array) $values as $value) {
                $parts[] = $key.'='.$value;
            }
        }

        return implode(', ', $parts);
    }
}
