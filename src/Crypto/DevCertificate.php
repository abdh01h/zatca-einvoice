<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Exception\SigningException;

/**
 * A throwaway secp256k1 key pair and self-signed certificate.
 *
 * Useful for local development, demos and tests. ZATCA does not accept
 * self-signed certificates for clearance or reporting: the real key pair comes
 * from the CSID issued during onboarding.
 */
final class DevCertificate
{
    public static function credentials(string $commonName = 'DEV-SANDBOX'): SigningCredentials
    {
        [$certificatePem, $privateKeyPem] = self::generate($commonName);

        return new SigningCredentials($certificatePem, $privateKeyPem);
    }

    /** @return array{0: string, 1: string} certificate PEM, private key PEM */
    public static function generate(string $commonName = 'DEV-SANDBOX'): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1',
        ]);

        if ($key === false) {
            throw new SigningException('Could not generate an secp256k1 key: '.openssl_error_string());
        }

        $csr = openssl_csr_new(
            ['commonName' => $commonName, 'countryName' => 'SA', 'organizationName' => 'ZATCA DEV'],
            $key,
            ['digest_alg' => 'sha256'],
        );

        if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new SigningException('Could not create a CSR: '.openssl_error_string());
        }

        $certificate = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new SigningException('Could not self-sign the development certificate: '.openssl_error_string());
        }

        if (! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $privateKeyPem)) {
            throw new SigningException('Could not export the development key material.');
        }

        return [$certificatePem, $privateKeyPem];
    }

    /** Writes certificate.pem and private-key.pem into $directory. */
    public static function writeTo(string $directory, string $commonName = 'DEV-SANDBOX'): SigningCredentials
    {
        [$certificatePem, $privateKeyPem] = self::generate($commonName);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true)) {
            throw new SigningException(sprintf('Cannot create directory "%s".', $directory));
        }

        file_put_contents($directory.'/certificate.pem', $certificatePem);
        file_put_contents($directory.'/private-key.pem', $privateKeyPem);
        chmod($directory.'/private-key.pem', 0o600);
        chmod($directory.'/certificate.pem', 0o644);

        return new SigningCredentials($certificatePem, $privateKeyPem);
    }
}
