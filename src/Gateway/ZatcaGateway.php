<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

use Zatca\EInvoice\Crypto\SignedDocument;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Exception\ZatcaException;

/**
 * The ZATCA Fatoora gateway: onboarding and the two submission flows.
 *
 * Shapes follow the published API (POST /compliance, /production/csids,
 * /invoices/clearance/single, /invoices/reporting/single) but have not been
 * exercised against the live developer portal, because that needs a taxpayer's
 * OTP. Parsing is deliberately tolerant: fields this class does not model are
 * left in `ClearanceResult::$payload` rather than thrown away.
 */
final class ZatcaGateway
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly GatewayConfig $config = new GatewayConfig(),
    ) {
    }

    /** Step 1 of onboarding: exchange a CSR and the portal OTP for a compliance CSID. */
    public function requestComplianceCsid(string $csrBase64, string $otp): Csid
    {
        $response = $this->http->send(new Request(
            method: 'POST',
            url: $this->config->url('/compliance'),
            headers: $this->config->headers() + ['OTP' => $otp],
            body: (string) json_encode(['csr' => $csrBase64]),
        ));

        return $this->csidFrom($response, 'Compliance CSID request');
    }

    /** Step 2: trade the compliance CSID for the production CSID. */
    public function requestProductionCsid(Csid $complianceCsid, string $complianceRequestId): Csid
    {
        $response = $this->http->send(new Request(
            method: 'POST',
            url: $this->config->url('/production/csids'),
            headers: $this->config->headers() + ['Authorization' => $complianceCsid->authorizationHeader()],
            body: (string) json_encode(['compliance_request_id' => $complianceRequestId]),
        ));

        return $this->csidFrom($response, 'Production CSID request');
    }

    /** Optional onboarding step: push a sample invoice through the compliance checks. */
    public function complianceCheck(Csid $complianceCsid, SignedDocument $document): Response
    {
        return $this->http->send(new Request(
            method: 'POST',
            url: $this->config->url('/compliance/invoices'),
            headers: $this->config->headers() + ['Authorization' => $complianceCsid->authorizationHeader()],
            body: $this->invoicePayload($document),
        ));
    }

    /** Standard (B2B) invoices are cleared before they are issued. */
    public function clearance(Csid $productionCsid, SignedDocument $document): ClearanceResult
    {
        return $this->submit('/invoices/clearance/single', 'Clearance', $productionCsid, $document);
    }

    /** Simplified (B2C) invoices are reported within 24 hours of issue. */
    public function reporting(Csid $productionCsid, SignedDocument $document): ClearanceResult
    {
        return $this->submit('/invoices/reporting/single', 'Reporting', $productionCsid, $document);
    }

    private function submit(string $path, string $operation, Csid $csid, SignedDocument $document): ClearanceResult
    {
        $response = $this->http->send(new Request(
            method: 'POST',
            url: $this->config->url($path),
            headers: $this->config->headers() + ['Authorization' => $csid->authorizationHeader()],
            body: $this->invoicePayload($document),
        ));

        if (! $response->isSuccessful()) {
            throw GatewayException::fromResponse($operation, $response);
        }

        $json = $response->json();
        $cleared = is_string($json['clearedInvoice'] ?? null) ? (string) $json['clearedInvoice'] : null;
        $clearedXml = $cleared === null ? null : base64_decode($cleared, true);

        return new ClearanceResult(
            status: $this->stringField($json, 'clearanceStatus') ?? $this->stringField($json, 'reportingStatus') ?? 'UNKNOWN',
            errors: $response->errorMessages(),
            clearedInvoiceXml: is_string($clearedXml) ? $clearedXml : null,
            qrPayload: is_string($clearedXml) ? $this->qrFrom($clearedXml) : null,
            requestId: $this->stringField($json, 'requestID') ?? '',
            payload: $json,
        );
    }

    private function invoicePayload(SignedDocument $document): string
    {
        $payload = [
            'invoiceHash' => $document->hash->base64(),
            'uuid' => $document->invoice->uuid->value,
            'invoice' => base64_encode($document->xml()),
        ];

        return (string) json_encode($payload);
    }

    private function csidFrom(Response $response, string $operation): Csid
    {
        if (! $response->isSuccessful()) {
            throw GatewayException::fromResponse($operation, $response);
        }

        $json = $response->json();
        $token = $json['binarySecurityToken'] ?? null;
        $secret = $json['secret'] ?? null;

        if (! is_string($token) || ! is_string($secret)) {
            throw new ZatcaException($operation.' succeeded but the response carried no binarySecurityToken/secret.');
        }

        return new Csid(
            binarySecurityToken: $token,
            secret: $secret,
            requestId: $this->stringField($json, 'requestID'),
        );
    }

    /** @param array<string, mixed> $json */
    private function stringField(array $json, string $key): ?string
    {
        $value = $json[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Pull the QR payload out of a cleared (stamped) invoice. */
    private function qrFrom(string $xml): ?string
    {
        $document = new \DOMDocument();

        if (! $document->loadXML($xml)) {
            return null;
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cac', \Zatca\EInvoice\Xml\Namespaces::CAC);
        $xpath->registerNamespace('cbc', \Zatca\EInvoice\Xml\Namespaces::CBC);

        $result = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject");

        if ($result === false) {
            return null;
        }

        $node = $result->item(0);

        return $node instanceof \DOMElement ? $node->nodeValue : null;
    }
}
