<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Domain\InvoiceSubtype;
use Zatca\EInvoice\Domain\InvoiceType;
use Zatca\EInvoice\Domain\LineItem;
use Zatca\EInvoice\Domain\Money;
use Zatca\EInvoice\Domain\Party;
use Zatca\EInvoice\Domain\Quantity;
use Zatca\EInvoice\Domain\TaxCategory;
use Zatca\EInvoice\Exception\InvalidArgumentException;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;
use Zatca\EInvoice\Validation\InvoiceValidator;

final class ValidationTest extends TestCase
{
    public function test_a_complete_standard_invoice_has_no_errors(): void
    {
        $result = InvoiceValidator::withDefaults()->validate(InvoiceFactory::standard());

        self::assertTrue($result->isValid(), implode("\n", $result->messages()));
    }

    public function test_standard_invoices_require_a_buyer(): void
    {
        $invoice = new Invoice(
            type: InvoiceType::Invoice,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-03'),
            seller: InvoiceFactory::seller(),
            buyer: null,
            lines: [new LineItem('Widget', Quantity::of(1), Money::fromDecimal('10.00'))],
            invoiceNumber: 'INV-1',
        );

        $ids = array_map(static fn ($v) => $v->ruleId, InvoiceValidator::withDefaults()->validate($invoice)->errors());

        self::assertContains('KSA-BUYER-REQUIRED-STANDARD', $ids);
        self::assertContains('KSA-BUYER-VAT-STANDARD', $ids);
    }

    public function test_simplified_invoices_warn_until_they_are_signed(): void
    {
        $result = InvoiceValidator::withDefaults()->validate(InvoiceFactory::simplified());
        $warnings = array_map(static fn ($v) => $v->ruleId, $result->warnings());

        self::assertTrue($result->isValid());
        self::assertContains('KSA-QR', $warnings);
    }

    public function test_a_malformed_previous_hash_is_an_error(): void
    {
        $invoice = InvoiceFactory::standard()->withPreviousInvoiceHash('not-a-hash');
        $errors = array_map(static fn ($v) => $v->ruleId, InvoiceValidator::withDefaults()->validate($invoice)->errors());

        self::assertContains('KSA-PIH-FORMAT', $errors);
    }

    public function test_a_well_formed_previous_hash_passes(): void
    {
        $invoice = InvoiceFactory::standard()->withPreviousInvoiceHash(
            \Zatca\EInvoice\Crypto\InvoiceHash::fromHex(hash('sha256', 'previous'))->base64OfHex(),
        );

        $errors = array_map(static fn ($v) => $v->ruleId, InvoiceValidator::withDefaults()->validate($invoice)->errors());

        self::assertNotContains('KSA-PIH-FORMAT', $errors);
    }

    public function test_an_invoice_without_a_number_is_an_error(): void
    {
        $invoice = InvoiceFactory::standard()->withInvoiceNumber('');
        $errors = array_map(static fn ($v) => $v->ruleId, InvoiceValidator::withDefaults()->validate($invoice)->errors());

        self::assertContains('KSA-INVOICE-NUMBER', $errors);
    }

    public function test_non_sar_currency_is_only_a_warning(): void
    {
        $invoice = new Invoice(
            type: InvoiceType::Invoice,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-03'),
            seller: InvoiceFactory::seller(),
            buyer: InvoiceFactory::buyer(),
            lines: [new LineItem('Export', Quantity::of(1), Money::fromDecimal('10.00', \Zatca\EInvoice\Domain\Currency::USD), TaxCategory::ZeroRated, exemptionReasonCode: 'VATEX-SA-32')],
            invoiceNumber: 'INV-2',
            currency: \Zatca\EInvoice\Domain\Currency::USD,
        );

        $result = InvoiceValidator::withDefaults()->validate($invoice);

        self::assertTrue($result->isValid());
        self::assertContains('KSA-CURRENCY-SAR', array_map(static fn ($v) => $v->ruleId, $result->warnings()));
    }

    public function test_credit_notes_must_reference_the_original_uuid(): void
    {
        $invoice = new Invoice(
            type: InvoiceType::CreditNote,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-04'),
            seller: InvoiceFactory::seller(),
            buyer: InvoiceFactory::buyer(),
            lines: [new LineItem('Refund', Quantity::of(1), Money::fromDecimal('10.00'))],
            invoiceNumber: 'CN-1',
            billingReferenceId: 'not-a-uuid',
        );

        $errors = array_map(static fn ($v) => $v->ruleId, InvoiceValidator::withDefaults()->validate($invoice)->errors());

        self::assertContains('KSA-NOTE-REFERENCE', $errors);
    }

    public function test_a_note_without_a_billing_reference_cannot_be_constructed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Invoice(
            type: InvoiceType::CreditNote,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-04'),
            seller: InvoiceFactory::seller(),
            buyer: InvoiceFactory::buyer(),
            lines: [new LineItem('Refund', Quantity::of(1), Money::fromDecimal('10.00'))],
            invoiceNumber: 'CN-2',
        );
    }

    public function test_exemption_codes_are_checked_against_the_vatex_pattern(): void
    {
        $invoice = new Invoice(
            type: InvoiceType::Invoice,
            subtype: InvoiceSubtype::Simplified,
            issueDate: new \DateTimeImmutable('2026-11-03'),
            seller: InvoiceFactory::seller(),
            buyer: null,
            lines: [new LineItem('Export', Quantity::of(1), Money::fromDecimal('10.00'), TaxCategory::ZeroRated, exemptionReasonCode: 'SOMETHING-ELSE')],
            invoiceNumber: 'INV-3',
        );

        $errors = array_map(static fn ($v) => $v->ruleId, InvoiceValidator::withDefaults()->validate($invoice)->errors());

        self::assertContains('KSA-EXEMPTION-CODE', $errors);
    }
}
