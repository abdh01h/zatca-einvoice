<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

use Zatca\EInvoice\Crypto\SigningCredentials;
use Zatca\EInvoice\Exception\SigningException;

/**
 * A Cryptographic Stamp Identifier returned by ZATCA.
 *
 * The token is the base64 DER certificate; the secret is the base64 private key.
 * ZATCA does not send PEM, so both are wrapped here before OpenSSL sees them.
 */
final readonly class Csid implements \Stringable
{
    public function __construct(
        public string $binarySecurityToken,
        public string $secret,
        public ?string $requestId = null,
    ) {
    }

    public function certificatePem(): string
    {
        return self::pem('CERTIFICATE', $this->binarySecurityToken);
    }

    public function privateKeyPem(): string
    {
        foreach (['PRIVATE KEY', 'EC PRIVATE KEY'] as $label) {
            $pem = self::pem($label, $this->secret);
            $key = openssl_pkey_get_private($pem);

            if ($key !== false) {
                return $pem;
            }
        }

        throw new SigningException('The CSID secret is not a readable private key.');
    }

    public function signingCredentials(): SigningCredentials
    {
        return new SigningCredentials($this->certificatePem(), $this->privateKeyPem());
    }

    /** HTTP Basic credential for clearance/reporting calls. */
    public function authorizationHeader(): string
    {
        return 'Basic '.base64_encode($this->binarySecurityToken.':'.$this->secret);
    }

    public function __toString(): string
    {
        return 'CSID '.($this->requestId ?? '(no request id)');
    }

    private static function pem(string $label, string $base64): string
    {
        $body = preg_replace('/\s+/', '', $base64) ?? '';

        return "-----BEGIN {$label}-----\n".chunk_split($body, 64, "\n")."-----END {$label}-----\n";
    }
}
