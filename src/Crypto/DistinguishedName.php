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

        // Flatten first, then reverse the whole list. Reversing only the keys
        // would leave repeated RDNs (the three DC= components of a ZATCA
        // issuer) in certificate order instead of RFC 4514 order.
        foreach ($attributes as $key => $values) {
            foreach ((array) $values as $value) {
                $parts[] = $key.'='.$value;
            }
        }

        return implode(', ', array_reverse($parts));
    }
}
