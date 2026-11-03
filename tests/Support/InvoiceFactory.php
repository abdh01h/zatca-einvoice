<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Support;

use Zatca\EInvoice\Domain\Address;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Domain\InvoiceSubtype;
use Zatca\EInvoice\Domain\InvoiceType;
use Zatca\EInvoice\Domain\LineItem;
use Zatca\EInvoice\Domain\Money;
use Zatca\EInvoice\Domain\Party;
use Zatca\EInvoice\Domain\Quantity;
use Zatca\EInvoice\Domain\TaxCategory;
use Zatca\EInvoice\Domain\VatNumber;

/** Fixtures shared by the feature tests. */
final class InvoiceFactory
{
    public static function seller(): Party
    {
        return new Party(
            registrationName: 'Acme Widgets Ltd',
            address: new Address('Prince Sultan Street', '3242', 'Al Olaya', 'Riyadh', '12212', 'SA', '4323'),
            vatNumber: new VatNumber('311111111101113'),
            commercialRegistrationNumber: '1010101010',
        );
    }

    public static function buyer(): Party
    {
        return new Party(
            registrationName: 'Beta Trading Co',
            address: new Address('King Fahd Road', '1111', 'Al Malaz', 'Riyadh', '11411', 'SA', '2223'),
            vatNumber: new VatNumber('300000000000003'),
        );
    }

    public static function standard(): Invoice
    {
        return Invoice::draft(
            InvoiceType::Invoice,
            InvoiceSubtype::Standard,
            new \DateTimeImmutable('2026-11-03 09:15:00', new \DateTimeZone('UTC')),
            self::seller(),
            self::buyer(),
            [
                new LineItem('Consulting hours', Quantity::of('12.5', 'HUR'), Money::fromDecimal('250.00')),
                new LineItem(
                    'Software licence',
                    Quantity::of(2, 'PCE'),
                    Money::fromDecimal('499.00'),
                    TaxCategory::Standard,
                    discount: Money::fromDecimal('50.00'),
                    discountReason: 'Volume discount',
                ),
            ],
        )->withInvoiceNumber('INV-2026-0001')->withCounterValue(7);
    }

    public static function simplified(): Invoice
    {
        return Invoice::draft(
            InvoiceType::Invoice,
            InvoiceSubtype::Simplified,
            new \DateTimeImmutable('2026-11-03 17:41:08', new \DateTimeZone('UTC')),
            self::seller(),
            null,
            [
                new LineItem('Arabic book', Quantity::of(1, 'PCE'), Money::fromDecimal('99.00')),
            ],
        )->withInvoiceNumber('POS-000123')->withCounterValue(42);
    }
}
