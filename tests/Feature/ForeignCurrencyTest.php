<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Crypto\DevCertificate;
use Zatca\EInvoice\Domain\Currency;
use Zatca\EInvoice\Domain\ExchangeRate;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Domain\InvoiceSubtype;
use Zatca\EInvoice\Domain\InvoiceType;
use Zatca\EInvoice\Domain\LineItem;
use Zatca\EInvoice\Domain\Money;
use Zatca\EInvoice\Domain\Quantity;
use Zatca\EInvoice\Exception\InvalidArgumentException;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;
use Zatca\EInvoice\Validation\InvoiceValidator;
use Zatca\EInvoice\Validation\SchemaValidator;
use Zatca\EInvoice\Xml\InvoiceDocumentBuilder;

final class ForeignCurrencyTest extends TestCase
{
    public function test_the_rate_converts_with_one_rounding_point(): void
    {
        self::assertSame('3.75', ExchangeRate::fromDecimal('3.750000')->toDecimal());
        self::assertSame('56.25', ExchangeRate::fromDecimal('3.75')
            ->convert(Money::fromDecimal('15.00', Currency::USD))
            ->toDecimal());
        self::assertSame('0.38', ExchangeRate::fromDecimal('0.3775')
            ->convert(Money::fromDecimal('1.00', Currency::USD))
            ->toDecimal());
    }

    public function test_a_foreign_currency_invoice_carries_a_sar_tax_total(): void
    {
        $invoice = $this->usdInvoice(new ExchangeRate(3_750_000));
        $document = (new InvoiceDocumentBuilder())->build($invoice);
        $xpath = $this->xpath($document);

        self::assertTrue((new SchemaValidator())->validate($document)->isValid());
        self::assertSame('USD', $xpath->query('//cbc:DocumentCurrencyCode')->item(0)?->nodeValue);
        self::assertSame('SAR', $xpath->query('//cbc:TaxCurrencyCode')->item(0)?->nodeValue);
        self::assertSame('3.75', $xpath->query('//cac:TaxExchangeRate/cbc:CalculationRate')->item(0)?->nodeValue);
        self::assertSame('SAR', $xpath->query('//cac:TaxExchangeRate/cbc:TargetCurrencyCode')->item(0)?->nodeValue);

        $totals = $xpath->query('/*/cac:TaxTotal');
        self::assertSame(2, $totals->length, 'One tax total with subtotals, one in SAR without.');
        self::assertSame('15.00', $xpath->query('/*/cac:TaxTotal[1]/cbc:TaxAmount')->item(0)?->nodeValue);
        self::assertSame('SAR', $xpath->query('/*/cac:TaxTotal[2]/cbc:TaxAmount')->item(0)?->getAttribute('currencyID'));
        self::assertSame('56.25', $xpath->query('/*/cac:TaxTotal[2]/cbc:TaxAmount')->item(0)?->nodeValue);
        self::assertSame(0, $xpath->query('/*/cac:TaxTotal[2]/cac:TaxSubtotal')->length);
    }

    public function test_a_foreign_currency_invoice_without_a_rate_is_an_error(): void
    {
        $invoice = $this->usdInvoice(null);
        $ids = array_map(
            static fn ($v): string => $v->ruleId,
            InvoiceValidator::withDefaults()->validate($invoice)->errors(),
        );

        self::assertContains('BR-KSA-EN16931-09', $ids);
    }

    public function test_a_sar_invoice_may_not_carry_a_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Invoice(
            type: InvoiceType::Invoice,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-03'),
            seller: InvoiceFactory::seller(),
            buyer: InvoiceFactory::buyer(),
            lines: [new LineItem('Widget', Quantity::of(1), Money::fromDecimal('10.00'))],
            invoiceNumber: 'INV-FX',
            taxExchangeRate: new ExchangeRate(3_750_000),
        );
    }

    private function usdInvoice(?ExchangeRate $rate): Invoice
    {
        return new Invoice(
            type: InvoiceType::Invoice,
            subtype: InvoiceSubtype::Standard,
            issueDate: new \DateTimeImmutable('2026-11-03 09:00:00', new \DateTimeZone('UTC')),
            seller: InvoiceFactory::seller(),
            buyer: InvoiceFactory::buyer(),
            lines: [new LineItem('Export licence', Quantity::of(1), Money::fromDecimal('100.00', Currency::USD))],
            invoiceNumber: 'INV-FX-1',
            currency: Currency::USD,
            taxExchangeRate: $rate,
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
