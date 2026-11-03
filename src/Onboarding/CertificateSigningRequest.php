<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Onboarding;

/** A generated CSR, ready to POST to /compliance, plus the key it belongs to. */
final readonly class CertificateSigningRequest
{
    public function __construct(
        public string $pem,
        public string $privateKeyPem,
        public string $derBase64,
    ) {
    }
}
