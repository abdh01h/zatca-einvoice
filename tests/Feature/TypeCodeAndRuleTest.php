<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Crypto\Canonicalizer;
use Zatca\EInvoice\Crypto\DevCertificate;
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Crypto\SigningCredentials;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Domain\InvoiceSubtype;
use Zatca\EInvoice\Domain\InvoiceType;
use Zatca\EInvoice\Domain\LineItem;
use Zatca\EInvoice\Domain\Money;
use Zatca\EInvoice\Domain\Party;
use Zatca\EInvoice\Domain\Quantity;
use Zatca\EInvoice\Domain\TaxCategory;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;
use Zatca\EInvoice\Validation\InvoiceValidator;

final class TypeCodeAndRuleTest extends TestCase
{
    public function test_export_and_self_billing_flags_cannot_be_combined(): void
    {
        $invoice = $this->withTypeName('0100101'); // export (pos 5) + self-billed (pos 7)

        self::assertContains('BR-KSA-07', $this->errorIds($invoice));
    }

    public function test_simplified_documents_reject_the_self_billed_flag(): void
    {
        $invoice = InvoiceFactory::simplified();
        $invoice = new Invoice(
            type: $invoice->type,
            subtype: InvoiceSubtype::Simplified,
            issueDate: $invoice->issueDate,
            seller: $invoice->seller,
            buyer: null,
            lines: $invoice->lines,
            invoiceNumber: 'POS-1',
            invoiceTypeName: '0200011',
        );

        self::assertContains('BR-KSA-31', $this->errorIds($invoice));
    }

    public function test_a_well_formed_flagged_type_name_passes(): void
    {
        // NN=01 standard, third party, nominal, summary -- all allowed
        $invoice = $this->withTypeName('0111010');

        self::assertNotContains('BR-KSA-06', $this->errorIds($invoice));
        self::assertNotContains('BR-KSA-07', $this->errorIds($invoice));
    }

    public function test_the_seller_building_number_must_be_four_digits(): void
    {
        $invoice = new Invoice(
            type: InvoiceType::Invoice,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-03 09:00:00', new \DateTimeZone('UTC')),
            seller: new Party(
                'Acme Widgets Ltd',
                new \Zatca\EInvoice\Domain\Address('Prince Sultan Street', '324', 'Al Olaya', 'Riyadh', '12212'),
                InvoiceFactory::seller()->vatNumber,
            ),
            buyer: InvoiceFactory::buyer(),
            lines: [new LineItem('Widget', Quantity::of(1), Money::fromDecimal('10.00'))],
            invoiceNumber: 'INV-9',
        );

        self::assertContains('BR-KSA-37', $this->errorIds($invoice));
    }

    public function test_an_exemption_code_without_reason_text_only_warns(): void
    {
        $invoice = new Invoice(
            type: InvoiceType::Invoice,
            subtype: InvoiceSubtype::Simplified,
            issueDate: new \DateTimeImmutable('2026-11-03 09:00:00', new \DateTimeZone('UTC')),
            seller: InvoiceFactory::seller(),
            buyer: null,
            lines: [new LineItem('Export', Quantity::of(1), Money::fromDecimal('10.00'), TaxCategory::ZeroRated, exemptionReasonCode: 'VATEX-SA-32')],
            invoiceNumber: 'INV-10',
        );

        $result = InvoiceValidator::withDefaults()->validate($invoice);

        self::assertContains('BR-KSA-83', array_map(static fn ($v) => $v->ruleId, $result->warnings()));
        self::assertNotContains('BR-KSA-23', array_map(static fn ($v) => $v->ruleId, $result->errors()));
    }

    public function test_the_exemption_reason_text_is_rendered(): void
    {
        $invoice = new Invoice(
            type: InvoiceType::Invoice,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-03 09:00:00', new \DateTimeZone('UTC')),
            seller: InvoiceFactory::seller(),
            buyer: InvoiceFactory::buyer(),
            lines: [new LineItem(
                'Export handling',
                Quantity::of(1),
                Money::fromDecimal('100.00'),
                TaxCategory::ZeroRated,
                exemptionReasonCode: 'VATEX-SA-32',
                exemptionReason: 'Export of goods',
            )],
            invoiceNumber: 'INV-11',
        );

        $xml = (new \Zatca\EInvoice\Xml\InvoiceDocumentBuilder())->build($invoice)->saveXML();

        self::assertIsString($xml);
        self::assertStringContainsString('<cbc:TaxExemptionReasonCode>VATEX-SA-32</cbc:TaxExemptionReasonCode><cbc:TaxExemptionReason>Export of goods</cbc:TaxExemptionReason>', $xml);
    }

    public function test_a_certificate_bundle_lands_in_the_signature(): void
    {
        [$firstCert, $firstKey] = DevCertificate::generate('LEAF');
        [$secondCert] = DevCertificate::generate('SUB-CA');
        $credentials = new SigningCredentials($firstCert.$secondCert, $firstKey);

        $signed = (new InvoiceSigner($credentials))->sign(InvoiceFactory::standard());
        $xpath = new \DOMXPath($signed->document);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        self::assertSame(2, $xpath->query('//ds:X509Data/ds:X509Certificate')->length);
        self::assertTrue($credentials->verify(
            Canonicalizer::canonicalize($xpath->query('//ds:SignedInfo')->item(0)),
            $signed->signatureValue,
        ));
    }

    public function test_the_qr_stamp_tag_is_optional_and_raw_der(): void
    {
        $credentials = DevCertificate::credentials();
        $signed = (new InvoiceSigner($credentials))->sign(InvoiceFactory::simplified());

        self::assertArrayNotHasKey(9, $signed->qrFields());

        $stamp = base64_decode('MEUCIQDexample', true);
        $payload = \Zatca\EInvoice\Qr\QrPayload::phaseTwo(
            InvoiceFactory::simplified(),
            $signed->hash,
            $signed->signatureValue,
            $credentials->publicKeyDer(),
            is_string($stamp) ? $stamp : null,
        );

        self::assertArrayHasKey(9, \Zatca\EInvoice\Qr\Tlv::decode($payload));
    }

    private function withTypeName(string $name): Invoice
    {
        $base = InvoiceFactory::standard();

        return new Invoice(
            type: $base->type,
            subtype: $base->subtype,
            issueDate: $base->issueDate,
            seller: $base->seller,
            buyer: $base->buyer,
            lines: $base->lines,
            invoiceNumber: 'INV-TYPE',
            invoiceTypeName: $name,
        );
    }

    /** @return list<string> */
    private function errorIds(Invoice $invoice): array
    {
        return array_map(
            static fn ($violation): string => $violation->ruleId,
            InvoiceValidator::withDefaults()->validate($invoice)->errors(),
        );
    }
}
