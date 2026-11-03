<?php

/**
 * A B2C point-of-sale invoice: simplified subtype, anonymous buyer, QR payload
 * printed as the customer would scan it.
 *
 *   php examples/pos-simplified-invoice.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Zatca\EInvoice\Crypto\{DevCertificate, InvoiceSigner};
use Zatca\EInvoice\Domain\{Address, Invoice, InvoiceSubtype, InvoiceType, LineItem, Money, Party, Quantity, VatNumber};
use Zatca\EInvoice\Qr\Tlv;

$seller = new Party(
    registrationName: 'Acme Coffee Roasters',
    address: new Address('Al Tahlia Street', '2711', 'Al Andalus', 'Jeddah', '23326', 'SA'),
    vatNumber: new VatNumber('311111111101113'),
);

$invoice = Invoice::draft(
    InvoiceType::Invoice,
    InvoiceSubtype::Simplified,
    new DateTimeImmutable('now', new DateTimeZone('Asia/Riyadh')),
    $seller,
    null,
    [
        new LineItem('Ethiopia Guji 250g', Quantity::of(1, 'PCE'), Money::fromDecimal('62.00')),
        new LineItem('V60 brew', Quantity::of(2, 'PCE'), Money::fromDecimal('21.50')),
    ],
)->withInvoiceNumber('POS-2026-000456')->withCounterValue(981);

$signed = (new InvoiceSigner(DevCertificate::credentials()))->sign($invoice);

$signed->save(__DIR__.'/output/pos.xml');

echo "Total: {$invoice->totals()->taxInclusiveAmount} SAR (VAT {$invoice->totals()->taxAmount})\n";
echo "QR payload:\n\n";

foreach (Tlv::decode($signed->qrPayload) as $tag => $value) {
    printf("  %d: %s\n", $tag, strlen($value) > 70 ? substr($value, 0, 67).'…' : $value);
}
