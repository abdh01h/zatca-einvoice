<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Exception\SigningException;

/**
 * The CSID certificate/key pair issued by ZATCA, plus the derived values the
 * XAdES signature needs (certificate digest, issuer name, serial, public key).
 */
final class SigningCredentials
{
    private readonly \OpenSSLAsymmetricKey $privateKey;

    /** @var array<string, mixed> */
    private readonly array $certificate;

    public function __construct(
        public readonly string $certificatePem,
        public readonly string $privateKeyPem,
    ) {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new SigningException('The private key could not be parsed: '.openssl_error_string());
        }

        $this->privateKey = $key;

        $certificate = openssl_x509_parse($certificatePem);

        if ($certificate === false) {
            throw new SigningException('The certificate could not be parsed: '.openssl_error_string());
        }

        $this->certificate = $certificate;

        $details = openssl_pkey_get_details($key);
        $curve = $details['ec']['curve_name'] ?? null;

        if ($curve !== 'secp256k1') {
            throw new SigningException(sprintf(
                'ZATCA requires an secp256k1 signing key; this one is "%s". Generate it with: openssl ecparam -name secp256k1 -genkey -noout -out key.pem',
                is_string($curve) ? $curve : 'not an EC key',
            ));
        }
    }

    public static function fromFiles(string $certificatePath, string $privateKeyPath): self
    {
        $certificate = @file_get_contents($certificatePath);
        $privateKey = @file_get_contents($privateKeyPath);

        if ($certificate === false || $privateKey === false) {
            throw new SigningException('Could not read the certificate or private key file.');
        }

        return new self($certificate, $privateKey);
    }

    public function certificateDer(): string
    {
        return self::pemBodyToDer($this->certificatePem, 'CERTIFICATE');
    }

    /** base64 of the lowercase hex SHA-256 of the DER certificate (ZATCA quirk). */
    public function certificateDigest(): string
    {
        return base64_encode(hash('sha256', $this->certificateDer()));
    }

    public function issuerName(): string
    {
        /** @var array<string, string|array<int, string>> $issuer */
        $issuer = $this->certificate['issuer'] ?? [];

        return DistinguishedName::render($issuer);
    }

    public function serialNumber(): string
    {
        $hex = $this->certificate['serialNumberHex'] ?? null;

        if (is_string($hex) && $hex !== '') {
            return Decimal::fromHex($hex);
        }

        $decimal = $this->certificate['serialNumber'] ?? '0';

        if (is_string($decimal) && str_starts_with($decimal, '0x')) {
            return Decimal::fromHex(substr($decimal, 2));
        }

        return is_string($decimal) ? $decimal : '0';
    }

    public function publicKeyDer(): string
    {
        $details = openssl_pkey_get_details($this->privateKey);

        if ($details === false || ! isset($details['key'])) {
            throw new SigningException('Could not export the public key.');
        }

        return self::pemBodyToDer($details['key'], 'PUBLIC KEY');
    }

    /** Base64 of the DER ECDSA signature over $data, as ZATCA expects. */
    public function sign(string $data): string
    {
        $signature = '';

        if (! openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new SigningException('Signing failed: '.openssl_error_string());
        }

        return base64_encode($signature);
    }

    public function verify(string $data, string $signatureBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);

        if ($signature === false) {
            return false;
        }

        return openssl_verify($data, $signature, $this->publicKeyPem(), OPENSSL_ALGO_SHA256) === 1;
    }

    public function publicKeyPem(): string
    {
        $details = openssl_pkey_get_details($this->privateKey);

        return $details['key'] ?? '';
    }

    private static function pemBodyToDer(string $pem, string $label): string
    {
        $pattern = '/-----BEGIN '.preg_quote($label, '/').'-----(.+?)-----END '.preg_quote($label, '/').'-----/s';

        if (preg_match($pattern, $pem, $matches) !== 1) {
            throw new SigningException(sprintf('The PEM block "%s" was not found.', $label));
        }

        $der = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);

        if ($der === false) {
            throw new SigningException('The PEM block is not valid base64.');
        }

        return $der;
    }
}
