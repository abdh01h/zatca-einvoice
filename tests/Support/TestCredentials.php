<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Support;

use Zatca\EInvoice\Crypto\SigningCredentials;

/** Generates a throwaway secp256k1 certificate that mimics a ZATCA CSID. */
final class TestCredentials
{
    public static function make(string $commonName = 'TSZEINVOICE-SubCA-1'): SigningCredentials
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1',
        ]);

        if ($key === false) {
            throw new \RuntimeException('Could not generate an EC key: '.openssl_error_string());
        }

        $csr = openssl_csr_new(
            [
                'countryName' => 'SA',
                'organizationName' => 'Acme Widgets Ltd',
                'organizationalUnitName' => 'TST',
                'commonName' => $commonName,
            ],
            $key,
            ['digest_alg' => 'sha256'],
        );

        if ($csr === false) {
            throw new \RuntimeException('Could not create a CSR: '.openssl_error_string());
        }

        $certificate = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new \RuntimeException('Could not self-sign the certificate: '.openssl_error_string());
        }

        if (! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $privateKeyPem)) {
            throw new \RuntimeException('Could not export the key material.');
        }

        return new SigningCredentials($certificatePem, $privateKeyPem);
    }
}
