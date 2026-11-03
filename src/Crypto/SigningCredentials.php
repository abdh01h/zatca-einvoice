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
        private readonly string $certificatePem,
        private readonly string $privateKeyPem,
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

        if (! openssl_x509_check_private_key($certificatePem, $key)) {
            throw new SigningException('The certificate and the private key do not belong together.');
        }

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
        $chain = $this->certificateChainDer();

        if ($chain === []) {
            throw new SigningException('The certificate PEM contains no certificate.');
        }

        return $chain[0];
    }

    /**
     * Every certificate in the PEM bundle, leaf first.
     *
     * ZATCA's samples carry a single CSID certificate, but the standard allows
     * (and some issuers expect) the chain to the trust anchor in ds:X509Data.
     *
     * @return list<string>
     */
    public function certificateChainDer(): array
    {
        $count = preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $this->certificatePem, $matches);

        if ($count === false || $count === 0) {
            throw new SigningException('The certificate PEM contains no certificate.');
        }

        $chain = [];

        foreach ($matches[1] as $body) {
            $der = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);

            if ($der === false) {
                throw new SigningException('A certificate in the PEM bundle is not valid base64.');
            }

            $chain[] = $der;
        }

        return $chain;
    }

    /**
     * base64 of the lowercase hex SHA-256 of the certificate's base64 text.
     *
     * ZATCA hashes the base64 body of the certificate -- not its DER bytes --
     * and then base64-encodes the hex digest. Verified against the
     * xades:CertDigest in ZATCA's own Simplified_Invoice.xml, where hashing
     * the DER produces a different value and hashing the base64 text
     * reproduces the stored 88-character digest exactly.
     */
    public function certificateDigest(): string
    {
        return self::certificateDigestFor($this->certificatePem);
    }

    /** The digest for any certificate, so callers can verify peers and fixtures. */
    public static function certificateDigestFor(string $certificatePem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $certificatePem, $matches) !== 1) {
            throw new SigningException('The certificate PEM is malformed.');
        }

        $body = preg_replace('/\s+/', '', $matches[1]) ?? '';

        return base64_encode(hash('sha256', $body));
    }

    public function certificatePem(): string
    {
        return $this->certificatePem;
    }

    /** The base64 body of the first certificate, without PEM armour or whitespace. */
    public function certificateBase64(): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $this->certificatePem, $matches) !== 1) {
            throw new SigningException('The certificate PEM is malformed.');
        }

        return preg_replace('/\s+/', '', $matches[1]) ?? '';
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

        $certificateKey = openssl_pkey_get_public($this->certificatePem);

        if ($certificateKey === false) {
            return false;
        }

        return openssl_verify($data, $signature, $certificateKey, OPENSSL_ALGO_SHA256) === 1;
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
