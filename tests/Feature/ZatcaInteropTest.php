<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Crypto\DevCertificate;
use Zatca\EInvoice\Crypto\DistinguishedName;
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Crypto\SigningCredentials;
use Zatca\EInvoice\Exception\SigningException;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;

/**
 * Byte-level interop with ZATCA's own sample document.
 *
 * These tests do not compare against values this library produced; they compare
 * against the xades:CertDigest and ds:X509IssuerName that ZATCA's SDK wrote into
 * Simplified_Invoice.xml. If they fail, a real CSID would be rejected.
 */
final class ZatcaInteropTest extends TestCase
{
    public function test_the_certificate_digest_matches_zatca_sample(): void
    {
        [$certificatePem, $expected] = $this->sampleCertificate();

        self::assertSame(
            $expected,
            SigningCredentials::certificateDigestFor($certificatePem),
            'ZATCA hashes the base64 certificate text, then base64-encodes the hex digest.',
        );
    }

    public function test_the_issuer_name_matches_zatca_sample(): void
    {
        [$certificatePem, , $expectedIssuer] = $this->sampleCertificate();
        $parsed = openssl_x509_parse($certificatePem);

        self::assertIsArray($parsed);

        /** @var array<string, string|array<int, string>> $issuer */
        $issuer = $parsed['issuer'];

        self::assertSame($expectedIssuer, DistinguishedName::render($issuer));
    }

    public function test_signing_an_already_signed_document_is_refused(): void
    {
        $credentials = DevCertificate::credentials();
        $signer = new InvoiceSigner($credentials);
        $signed = $signer->sign(InvoiceFactory::standard());

        $this->expectException(SigningException::class);
        $signer->sign(InvoiceFactory::standard(), $signed->document);
    }

    public function test_a_private_key_that_does_not_match_the_certificate_is_refused(): void
    {
        [$firstCert] = DevCertificate::generate('FIRST');
        [, $secondKey] = DevCertificate::generate('SECOND');

        $this->expectException(SigningException::class);
        new SigningCredentials($firstCert, $secondKey);
    }

    /** @return array{0: string, 1: string, 2: string} certificate PEM, cert digest, issuer name */
    private function sampleCertificate(): array
    {
        $document = new \DOMDocument();
        $document->load(__DIR__.'/../fixtures/zatca-samples/simplified/Simplified_Invoice.xml');

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

        $body = $xpath->query('//ds:X509Certificate')->item(0)?->nodeValue ?? '';
        $digest = $xpath->query('//xades:CertDigest//ds:DigestValue')->item(0)?->nodeValue ?? '';
        $issuer = $xpath->query('//ds:X509IssuerName')->item(0)?->nodeValue ?? '';

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";

        return [$pem, $digest, $issuer];
    }
}
