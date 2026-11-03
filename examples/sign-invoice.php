<?php

/**
 * Build, validate and sign one invoice, then write both the submittable XML and
 * an indented copy for reading.
 *
 *   php examples/sign-invoice.php [--key=DIR]
 *
 * Without --key a throwaway secp256k1 certificate is generated: ZATCA only
 * accepts certificates issued to you during onboarding.
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Crypto\SigningCredentials;
use Zatca\EInvoice\Domain\{Address, Invoice, InvoiceSubtype, InvoiceType, LineItem, Money, Party, Quantity, TaxCategory, VatNumber};
use Zatca\EInvoice\Validation\{InvoiceValidator, SchemaValidator};

$options = getopt('', ['key::']);
$keyDirectory = is_string($options['key'] ?? null) ? $options['key'] : null;

$credentials = $keyDirectory === null
    ? \Zatca\EInvoice\Crypto\DevCertificate::credentials()
    : SigningCredentials::fromFiles($keyDirectory.'/certificate.pem', $keyDirectory.'/private-key.pem');

$seller = new Party(
    registrationName: 'Acme Widgets Ltd',
    address: new Address('Prince Sultan Street', '3242', 'Al Olaya', 'Riyadh', '12212', 'SA', '4323'),
    vatNumber: new VatNumber('311111111101113'),
    commercialRegistrationNumber: '1010101010',
);

$invoice = Invoice::draft(
    InvoiceType::Invoice,
    InvoiceSubtype::Standard,
    new DateTimeImmutable('2026-11-03 09:15:00', new DateTimeZone('UTC')),
    $seller,
    new Party(
        'Beta Trading Co',
        new Address('King Fahd Road', '1111', 'Al Malaz', 'Riyadh', '11411', 'SA', '2223'),
        new VatNumber('300000000000003'),
    ),
    [
        new LineItem('Consulting hours', Quantity::of('12.5', 'HUR'), Money::fromDecimal('250.00')),
        new LineItem('Software licence', Quantity::of(2, 'PCE'), Money::fromDecimal('499.00'), discount: Money::fromDecimal('50.00'), discountReason: 'Volume discount'),
        new LineItem('Export handling', Quantity::of(1, 'PCE'), Money::fromDecimal('100.00'), TaxCategory::ZeroRated, exemptionReasonCode: 'VATEX-SA-32'),
    ],
)->withInvoiceNumber('INV-2026-0001')->withCounterValue(7);

$rules = InvoiceValidator::withDefaults()->validate($invoice);
$rules->throwIfInvalid();

$signed = (new InvoiceSigner($credentials))->sign($invoice);
$schema = (new SchemaValidator())->validate($signed->document);
$schema->throwIfInvalid();

$signed->save(__DIR__.'/output/invoice.xml');
$signed->saveForDisplay(__DIR__.'/output/invoice.pretty.xml');

printf("Invoice %s signed: %s SAR total, %s VAT\n", $invoice->documentId(), $invoice->totals()->taxInclusiveAmount, $invoice->totals()->taxAmount);
printf("Hash %s\nQR   %s\n", $signed->hash->base64(), $signed->qrPayload);
