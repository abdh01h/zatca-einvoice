<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Domain\AllowanceCharge;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Domain\InvoiceSubtype;
use Zatca\EInvoice\Domain\InvoiceType;
use Zatca\EInvoice\Domain\LineItem;
use Zatca\EInvoice\Domain\Money;
use Zatca\EInvoice\Domain\Quantity;
use Zatca\EInvoice\Domain\TaxCategory;
use Zatca\EInvoice\Domain\Uuid;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;
use Zatca\EInvoice\Validation\SchemaValidator;
use Zatca\EInvoice\Xml\InvoiceDocumentBuilder;
use Zatca\EInvoice\Xml\RootElement;

final class InvoiceDocumentTest extends TestCase
{
    public function test_a_standard_invoice_produces_schema_valid_ubl(): void
    {
        $invoice = InvoiceFactory::standard();
        $document = (new InvoiceDocumentBuilder())->build($invoice);

        self::assertSame('Invoice', $document->documentElement?->localName);

        $result = (new SchemaValidator())->validate($document);
        self::assertTrue($result->isValid(), implode("\n", $result->messages()));

        $xml = $document->saveXML();
        self::assertIsString($xml);
        self::assertStringContainsString('<cbc:ProfileID>reporting:1.0</cbc:ProfileID>', $xml);
        self::assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">388</cbc:InvoiceTypeCode>', $xml);
        self::assertStringContainsString('<cbc:DocumentCurrencyCode>SAR</cbc:DocumentCurrencyCode>', $xml);
        self::assertStringContainsString('<cbc:TaxCurrencyCode>SAR</cbc:TaxCurrencyCode>', $xml);
    }

    public function test_a_simplified_invoice_declares_the_reporting_profile(): void
    {
        $document = (new InvoiceDocumentBuilder())->build(InvoiceFactory::simplified());
        $xpath = $this->xpath($document);

        self::assertSame('reporting:1.0', $xpath->query('//cbc:ProfileID')->item(0)?->nodeValue);
        self::assertSame('0200000', $xpath->query('//cbc:InvoiceTypeCode')->item(0)?->getAttribute('name'));
        self::assertSame(1, $xpath->query('//cac:AccountingCustomerParty')->length, 'UBL requires the element even for B2C.');
        self::assertSame(0, $xpath->query('//cac:AccountingCustomerParty//cbc:RegistrationName')->length, 'The anonymous buyer carries no name.');
        self::assertTrue((new SchemaValidator())->validate($document)->isValid());
    }

    public function test_totals_in_the_document_match_the_calculator(): void
    {
        $invoice = InvoiceFactory::standard();
        $totals = $invoice->totals();
        $xpath = $this->xpath((new InvoiceDocumentBuilder())->build($invoice));

        self::assertSame($totals->taxExclusiveAmount->toDecimal(), $xpath->query('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount')->item(0)?->nodeValue);
        self::assertSame($totals->taxAmount->toDecimal(), $xpath->query('//cac:TaxTotal/cbc:TaxAmount')->item(0)?->nodeValue);
        self::assertSame($totals->taxInclusiveAmount->toDecimal(), $xpath->query('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')->item(0)?->nodeValue);
        self::assertSame($totals->payableAmount->toDecimal(), $xpath->query('//cac:LegalMonetaryTotal/cbc:PayableAmount')->item(0)?->nodeValue);
        self::assertSame('1', (string) $xpath->query('//cac:TaxSubtotal')->length);
    }

    public function test_a_credit_note_can_use_a_native_root(): void
    {
        $invoice = $this->creditNote();
        $document = (new InvoiceDocumentBuilder(RootElement::CreditNote))->build($invoice);

        self::assertSame('CreditNote', $document->documentElement?->localName);

        $result = (new SchemaValidator())->validate($document, RootElement::CreditNote);
        self::assertTrue($result->isValid(), implode("\n", $result->messages()));

        $xpath = $this->xpath($document);
        self::assertSame('381', $xpath->query('//cbc:CreditNoteTypeCode')->item(0)?->nodeValue);
        self::assertSame(1, $xpath->query('//cac:CreditNoteLine')->length);
    }

    public function test_a_credit_note_on_an_invoice_root_matches_zatca_samples(): void
    {
        $document = (new InvoiceDocumentBuilder())->build($this->creditNote());
        $xpath = $this->xpath($document);

        self::assertSame('Invoice', $document->documentElement?->localName);
        self::assertSame('381', $xpath->query('//cbc:InvoiceTypeCode')->item(0)?->nodeValue);
        self::assertSame(1, $xpath->query('//cac:InvoiceLine')->length);
        self::assertTrue((new SchemaValidator())->validate($document)->isValid());
    }

    public function test_document_level_allowances_reduce_the_taxable_base(): void
    {
        $invoice = InvoiceFactory::standard();
        $withAllowance = new Invoice(
            type: $invoice->type,
            subtype: $invoice->subtype,
            issueDate: $invoice->issueDate,
            seller: $invoice->seller,
            buyer: $invoice->buyer,
            lines: $invoice->lines,
            adjustments: [new AllowanceCharge(Money::fromDecimal('100.00'), false, 'Loyalty discount')],
        );

        $totals = $withAllowance->totals();
        $xpath = $this->xpath((new InvoiceDocumentBuilder())->build($withAllowance));

        self::assertSame('100.00', $totals->allowanceTotalAmount->toDecimal());
        self::assertSame('100.00', $xpath->query('//cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount')->item(0)?->nodeValue);
        self::assertSame($totals->taxExclusiveAmount->toDecimal(), $xpath->query('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount')->item(0)?->nodeValue);
        self::assertSame(1, $xpath->query('//cac:AllowanceCharge')->length);
    }

    private function creditNote(): Invoice
    {
        return new Invoice(
            type: InvoiceType::CreditNote,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-04 10:00:00', new \DateTimeZone('UTC')),
            seller: InvoiceFactory::seller(),
            buyer: InvoiceFactory::buyer(),
            lines: [
                new LineItem('Returned licence', Quantity::of(2, 'PCE'), Money::fromDecimal('499.00'), TaxCategory::Standard),
            ],
            invoiceNumber: 'CN-2026-0001',
            uuid: Uuid::v4(),
            counterValue: 8,
            billingReferenceId: '0f2b9c1e-5a4d-4f7b-9c2e-1a2b3c4d5e6f',
            billingReferenceIssueDate: new \DateTimeImmutable('2026-11-03'),
            note: 'Customer returned the licence',
        );
    }

    private function xpath(\DOMDocument $document): \DOMXPath
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xpath;
    }
}
