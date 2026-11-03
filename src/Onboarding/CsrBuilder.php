<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Onboarding;

use Zatca\EInvoice\Crypto\Der;
use Zatca\EInvoice\Exception\SigningException;

/**
 * Builds the certificate signing request ZATCA's onboarding expects.
 *
 * Profile: secp256k1 key, CN prefixed TST (compliance) or PRD (production),
 * `serialNumber` carrying `1-manufacturer|2-model|3-serial`, and a custom
 * extension (OID 1.3.6.1.4.1.311.20.2) whose value is the certificate template
 * name `ZATCA-Code-Signing`.
 *
 * The CSR is DER-encoded directly rather than produced with openssl_csr_new():
 * OpenSSL types `serialNumber` as PrintableString and refuses the pipe
 * characters the profile requires. The result is verifiable with
 * `openssl req -in request.csr -noout -text`.
 */
final class CsrBuilder
{
    private const OID_C = '2.5.4.6';
    private const OID_SERIAL_NUMBER = '2.5.4.5';
    private const OID_O = '2.5.4.10';
    private const OID_OU = '2.5.4.11';
    private const OID_CN = '2.5.4.3';
    private const OID_EXTENSION_REQUEST = '1.2.840.113549.1.9.14';
    private const OID_EC_PUBLIC_KEY = '1.2.840.10045.2.1';
    private const OID_SECP256K1 = '1.3.132.0.10';
    private const OID_ECDSA_SHA256 = '1.2.840.10045.4.3.2';
    private const TEMPLATE_OID = '1.3.6.1.4.1.311.20.2';
    private const TEMPLATE_VALUE = 'ZATCA-Code-Signing';

    public function build(Egs $egs): CertificateSigningRequest
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1',
        ]);

        if ($key === false) {
            throw new SigningException('Could not generate the secp256k1 key: '.openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new SigningException('Could not read the public point of the generated key.');
        }

        $requestInfo = Der::sequence(
            Der::integer(0),
            $this->subject($egs),
            $this->subjectPublicKeyInfo($details['ec']['x'], $details['ec']['y']),
            $this->attributes(),
        );

        $signature = '';
        if (! openssl_sign($requestInfo, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new SigningException('Could not sign the CSR: '.openssl_error_string());
        }

        $request = Der::sequence(
            $requestInfo,
            Der::sequence(Der::oid(self::OID_ECDSA_SHA256)),
            Der::bitString($signature),
        );

        if (! openssl_pkey_export($key, $privateKeyPem)) {
            throw new SigningException('Could not export the private key.');
        }

        return new CertificateSigningRequest(
            pem: $this->pem($request),
            privateKeyPem: $privateKeyPem,
            derBase64: base64_encode($request),
        );
    }

    private function subject(Egs $egs): string
    {
        $rdns = [
            Der::set(Der::sequence(Der::oid(self::OID_C), Der::printableString('SA'))),
            Der::set(Der::sequence(Der::oid(self::OID_O), Der::utf8String($egs->organizationName))),
            Der::set(Der::sequence(Der::oid(self::OID_OU), Der::utf8String($egs->branchName))),
            Der::set(Der::sequence(Der::oid(self::OID_CN), Der::utf8String($egs->commonName()))),
            Der::set(Der::sequence(Der::oid(self::OID_SERIAL_NUMBER), Der::utf8String($egs->serialNumberAttribute()))),
        ];

        return Der::sequence(...$rdns);
    }

    private function subjectPublicKeyInfo(string $x, string $y): string
    {
        $point = "\x04".$x.$y;

        return Der::sequence(
            Der::sequence(Der::oid(self::OID_EC_PUBLIC_KEY), Der::oid(self::OID_SECP256K1)),
            Der::bitString($point),
        );
    }

    private function attributes(): string
    {
        // Extension ::= SEQUENCE { extnID, extnValue } -- the template name is a
        // UTF8String wrapped in an OCTET STRING, non-critical.
        $extension = Der::sequence(
            Der::oid(self::TEMPLATE_OID),
            Der::octetString(Der::utf8String(self::TEMPLATE_VALUE)),
        );

        $extensionRequest = Der::sequence(
            Der::oid(self::OID_EXTENSION_REQUEST),
            Der::set(Der::sequence($extension)),
        );

        return Der::context(0, $extensionRequest);
    }

    private function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE REQUEST-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END CERTIFICATE REQUEST-----\n";
    }
}
