<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Gateway\Csid;
use Zatca\EInvoice\Onboarding\CsrBuilder;
use Zatca\EInvoice\Onboarding\Egs;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;

final class OnboardingTest extends TestCase
{
    public function test_the_csr_carries_the_zatca_profile(): void
    {
        $request = (new CsrBuilder())->build($this->egs());
        $subject = openssl_csr_get_subject($request->pem);

        self::assertIsArray($subject);
        self::assertSame('SA', $subject['C'] ?? null);
        self::assertSame('Acme Widgets Ltd', $subject['O'] ?? null);
        self::assertSame('Riyadh Branch', $subject['OU'] ?? null);
        self::assertSame('TST-1234567890', $subject['CN'] ?? null);
        self::assertSame('1-Acme|2-POS-1|3-1234567890', $subject['serialNumber'] ?? null);
    }

    public function test_the_csr_key_and_der_are_what_zatca_expects(): void
    {
        $request = (new CsrBuilder())->build($this->egs());
        $key = openssl_pkey_get_private($request->privateKeyPem);
        $details = openssl_pkey_get_details($key);

        self::assertIsArray($details);
        self::assertSame('secp256k1', $details['ec']['curve_name'] ?? null);

        $der = base64_decode($request->derBase64, true);
        self::assertIsString($der);
        self::assertStringContainsString('ZATCA-Code-Signing', $der);
        self::assertStringContainsString(hex2bin('06092b0601040182371402'), $der, 'template extension OID');
    }

    public function test_the_csr_is_signed_and_parses_as_pkcs10(): void
    {
        $request = (new CsrBuilder())->build($this->egs());
        $csr = openssl_csr_get_public_key($request->pem);

        self::assertNotFalse($csr, 'OpenSSL must accept the hand-encoded CSR.');
    }

    public function test_a_csid_converts_back_into_working_credentials(): void
    {
        [$certificatePem, $privateKeyPem] = \Zatca\EInvoice\Crypto\DevCertificate::generate('CSID');

        $csid = new Csid(
            binarySecurityToken: $this->pemBody($certificatePem, 'CERTIFICATE'),
            secret: $this->pemBody($privateKeyPem, 'PRIVATE KEY'),
            requestId: 'req-1',
        );

        $signed = (new InvoiceSigner($csid->signingCredentials()))->sign(InvoiceFactory::standard());

        self::assertNotSame('', $signed->signatureValue);
        self::assertSame(1, $signed->qrFields()[1] === 'Acme Widgets Ltd' ? 1 : 0);
        self::assertStringStartsWith('Basic ', $csid->authorizationHeader());
    }

    private function egs(): Egs
    {
        return new Egs('Acme', 'POS-1', '1234567890', 'Acme Widgets Ltd', 'Riyadh Branch');
    }

    private function pemBody(string $pem, string $label): string
    {
        if (preg_match('/-----BEGIN '.$label.'-----(.+?)-----END '.$label.'-----/s', $pem, $matches) !== 1) {
            self::fail('No '.$label.' block in the fixture.');
        }

        return preg_replace('/\s+/', '', $matches[1]) ?? '';
    }
}
