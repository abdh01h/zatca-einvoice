<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Crypto\DevCertificate;
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Gateway\Csid;
use Zatca\EInvoice\Gateway\GatewayConfig;
use Zatca\EInvoice\Gateway\GatewayException;
use Zatca\EInvoice\Gateway\Response;
use Zatca\EInvoice\Gateway\ZatcaGateway;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;
use Zatca\EInvoice\Tests\Support\StubHttpClient;

/**
 * The gateway is exercised through its transport seam: these tests pin the
 * request shapes we send and the tolerant parsing of what comes back, not the
 * live service (that needs a taxpayer OTP).
 */
final class GatewayTest extends TestCase
{
    public function test_it_requests_a_compliance_csid_with_the_otp_header(): void
    {
        $http = new StubHttpClient([
            new Response(200, json_encode([
                'requestID' => 'req-1',
                'binarySecurityToken' => base64_encode('cert'),
                'secret' => base64_encode('key'),
            ], JSON_THROW_ON_ERROR)),
        ]);

        $csid = (new ZatcaGateway($http))->requestComplianceCsid('BASE64CSR', '123456');

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->method);
        self::assertSame(GatewayConfig::SANDBOX.'/compliance', $request->url);
        self::assertSame('123456', $request->headers['OTP']);
        self::assertSame(['csr' => 'BASE64CSR'], json_decode($request->body, true));
        self::assertSame('req-1', $csid->requestId);
    }

    public function test_it_exchanges_a_compliance_csid_for_a_production_one(): void
    {
        $http = new StubHttpClient([
            new Response(201, json_encode(['binarySecurityToken' => 'c', 'secret' => 's'], JSON_THROW_ON_ERROR)),
        ]);

        $compliance = new Csid('token', 'secret', 'req-1');
        (new ZatcaGateway($http))->requestProductionCsid($compliance, 'req-1');

        $request = $http->lastRequest();
        self::assertNotNull($request);
        self::assertSame(GatewayConfig::SANDBOX.'/production/csids', $request->url);
        self::assertSame('Basic '.base64_encode('token:secret'), $request->headers['Authorization']);
        self::assertSame(['compliance_request_id' => 'req-1'], json_decode($request->body, true));
    }

    public function test_clearance_sends_the_signed_document_and_reads_the_stamp_back(): void
    {
        $signed = (new InvoiceSigner(DevCertificate::credentials()))->sign(InvoiceFactory::standard());

        $http = new StubHttpClient([
            new Response(200, json_encode([
                'clearanceStatus' => 'CLEARED',
                'clearedInvoice' => base64_encode($signed->xml()),
                'requestID' => 'clear-1',
                'validationResults' => ['status' => 'PASS', 'errorMessages' => []],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = (new ZatcaGateway($http))->clearance(new Csid('t', 's'), $signed);

        $body = json_decode((string) $http->lastRequest()?->body, true);
        self::assertIsArray($body);
        self::assertSame($signed->hash->base64(), $body['invoiceHash']);
        self::assertSame($signed->invoice->uuid->value, $body['uuid']);
        self::assertSame($signed->xml(), base64_decode((string) $body['invoice'], true));
        self::assertStringEndsWith('/invoices/clearance/single', (string) $http->lastRequest()?->url);

        self::assertTrue($result->isAccepted());
        self::assertSame('CLEARED', $result->status);
        self::assertSame($signed->qrPayload, $result->qrPayload, 'The stamp travels in the cleared invoice QR.');
        self::assertSame('clear-1', $result->requestId);
    }

    public function test_reporting_returns_the_reported_status(): void
    {
        $signed = (new InvoiceSigner(DevCertificate::credentials()))->sign(InvoiceFactory::simplified());

        $http = new StubHttpClient([
            new Response(200, json_encode([
                'reportingStatus' => 'REPORTED',
                'validationResults' => ['status' => 'PASS', 'errorMessages' => []],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = (new ZatcaGateway($http))->reporting(new Csid('t', 's'), $signed);

        self::assertTrue($result->isAccepted());
        self::assertSame('REPORTED', $result->status);
        self::assertNull($result->clearedInvoiceXml);
    }

    public function test_a_rejected_submission_raises_with_zatca_messages(): void
    {
        $http = new StubHttpClient([
            new Response(400, json_encode([
                'validationResults' => [
                    'status' => 'ERROR',
                    'errorMessages' => [
                        ['code' => 'BR-KSA-39', 'message' => 'seller VAT number missing'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            (new ZatcaGateway($http))->clearance(
                new Csid('t', 's'),
                (new InvoiceSigner(DevCertificate::credentials()))->sign(InvoiceFactory::standard()),
            );
            self::fail('Expected a GatewayException.');
        } catch (GatewayException $exception) {
            self::assertSame(400, $exception->status);
            self::assertStringContainsString('seller VAT number missing', $exception->getMessage());
            self::assertSame(['seller VAT number missing'], $exception->messages);
        }
    }

    public function test_a_plain_https_configuration_is_enforced(): void
    {
        $this->expectException(\Zatca\EInvoice\Exception\InvalidArgumentException::class);

        new GatewayConfig('http://insecure.example');
    }
}
