<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

use Zatca\EInvoice\Crypto\InvoiceHash;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Domain\InvoiceCalculator;
use Zatca\EInvoice\Domain\InvoiceSubtype;
use Zatca\EInvoice\Domain\TaxCategory;

/**
 * The ruleset this library enforces before a document is signed.
 *
 * Design note: anything the domain model already makes unrepresentable (a
 * seller without a VAT number, an empty invoice, a discount larger than its
 * line) is *not* re-checked here. These rules cover what the model cannot
 * know: which fields ZATCA requires for which document type, how the totals
 * must reconcile, and the shape of the values ZATCA parses.
 *
 * Rule identifiers are local and stable; docs/rules.md maps each one to the
 * corresponding ZATCA BR-KSA rule.
 */
final class ZatcaRules
{
    /** @return list<Rule> */
    public static function default(): array
    {
        return [
            new CallableRule(
                'KSA-SELLER-ADDRESS',
                'The seller needs a structured national address (street, building number, district, city, postal code).',
                Severity::Error,
                static fn (Invoice $invoice): ?string => $invoice->seller->address === null
                    ? 'Seller postal address is missing; Phase 2 rejects free-text or absent addresses.'
                    : null,
            ),
            new CallableRule(
                'KSA-SELLER-CRN',
                'The seller commercial registration number is recommended and expected by most ERP integrations.',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->seller->commercialRegistrationNumber === null
                    ? 'Seller has no commercial registration number (CRN).'
                    : null,
            ),
            new CallableRule(
                'KSA-BUYER-REQUIRED-STANDARD',
                'A standard (B2B) invoice must identify the buyer.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => ! $invoice->isSimplified() && $invoice->buyer === null
                    ? 'Standard invoices require a buyer party; use the simplified subtype for walk-in customers.'
                    : null,
            ),
            new CallableRule(
                'KSA-BUYER-VAT-STANDARD',
                'A standard (B2B) invoice must carry the buyer VAT registration number.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => ! $invoice->isSimplified() && $invoice->buyer?->vatNumber === null
                    ? 'Standard invoices require the buyer VAT number (15 digits, starting and ending with 3).'
                    : null,
            ),
            new CallableRule(
                'KSA-BUYER-ADDRESS-STANDARD',
                'Buyers with a Saudi address must supply building number, street, district, city and postal code.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    if ($invoice->buyer === null || $invoice->buyer->address !== null) {
                        return null;
                    }

                    return 'Buyer postal address is missing; ZATCA requires the structured national address for SA buyers.';
                },
            ),
            new CallableRule(
                'KSA-PAYMENT-MEANS',
                'Standard invoices carry a payment means code (UN/ECE 4461).',
                Severity::Error,
                static fn (Invoice $invoice): ?string => ! $invoice->isSimplified() && $invoice->paymentMeansCode === null
                    ? 'Payment means code is required for standard invoices.'
                    : null,
            ),
            new CallableRule(
                'KSA-INVOICE-NUMBER',
                'cbc:ID carries the seller\'s own sequential invoice number.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => $invoice->invoiceNumber === null || trim($invoice->invoiceNumber) === ''
                    ? 'Invoice number is missing; cbc:ID must be the seller\'s sequential document number.'
                    : null,
            ),
            new CallableRule(
                'KSA-TYPE-NAME',
                'cbc:InvoiceTypeCode@name is seven digits: 1 (standard) or 2 (simplified) then ZATCA flags.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => preg_match('/^0[12][01]{5}$/', $invoice->typeNameAttribute()) === 1
                    ? null
                    : sprintf('Type name "%s" is not a seven-digit ZATCA type code.', $invoice->typeNameAttribute()),
            ),
            new CallableRule(
                'KSA-CURRENCY-SAR',
                'ZATCA tax amounts are expressed in SAR; a foreign document currency needs a SAR tax total as well.',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->currency->value === 'SAR'
                    ? null
                    : sprintf('Document currency is %s; ZATCA also requires the tax amounts in SAR.', $invoice->currency->value),
            ),
            new CallableRule(
                'KSA-ISSUE-DATE',
                'Issue dates may not be more than five days in the future.',
                Severity::Warning,
                static function (Invoice $invoice): ?string {
                    $limit = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+5 days');

                    return $invoice->issueDate > $limit
                        ? sprintf('Issue date %s is far in the future.', $invoice->issueDate->format('Y-m-d'))
                        : null;
                },
            ),
            new CallableRule(
                'KSA-PREPAID',
                'The prepaid amount cannot exceed the tax-inclusive total.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $totals = InvoiceCalculator::totals($invoice);

                    return $totals->prepaidAmount->minorUnits > $totals->taxInclusiveAmount->minorUnits
                        ? 'Prepaid amount is larger than the tax-inclusive total.'
                        : null;
                },
            ),
            new CallableRule(
                'KSA-TAX-SUBTOTALS',
                'Tax subtotals must add up to the invoice tax amount.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $totals = InvoiceCalculator::totals($invoice);
                    $sum = 0;

                    foreach ($totals->taxSubtotals as $subtotal) {
                        $sum += $subtotal->taxAmount->minorUnits;
                    }

                    return $sum !== $totals->taxAmount->minorUnits
                        ? sprintf('Tax subtotals sum to %d minor units but the invoice tax is %d.', $sum, $totals->taxAmount->minorUnits)
                        : null;
                },
            ),
            new CallableRule(
                'KSA-STANDARD-RATE',
                'The standard category uses the 15% rate unless a legacy rate is set explicitly.',
                Severity::Warning,
                static function (Invoice $invoice): ?string {
                    foreach ($invoice->lines as $line) {
                        if ($line->taxCategory === TaxCategory::Standard && $line->taxRateBasisPoints() !== TaxCategory::STANDARD_RATE_BASIS_POINTS) {
                            return sprintf('Line "%s" is standard-rated at %s%%.', $line->name, $line->taxRateBasisPoints() / 100);
                        }
                    }

                    return null;
                },
            ),
            new CallableRule(
                'KSA-EXEMPTION-CODE',
                'Zero-rated, exempt and out-of-scope lines use a VATEX-SA-* exemption reason code.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    foreach ($invoice->lines as $line) {
                        $code = $line->exemptionReasonCode;

                        if ($line->taxCategory->requiresExemptionReason() && ($code === null || preg_match('/^VATEX-SA-[A-Z0-9-]+$/', $code) !== 1)) {
                            return sprintf('Line "%s" has no valid VATEX-SA exemption reason code.', $line->name);
                        }
                    }

                    return null;
                },
            ),
            new CallableRule(
                'KSA-NOTE-REFERENCE',
                'Credit and debit notes reference the UUID of the invoice they correct.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    if (! $invoice->type->isNote()) {
                        return null;
                    }

                    $reference = $invoice->billingReferenceId;

                    if ($reference === null) {
                        return 'Missing billing reference.';
                    }

                    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) === 1
                        ? null
                        : sprintf('Billing reference "%s" is not the UUID of the original invoice.', $reference);
                },
            ),
            new CallableRule(
                'KSA-NOTE-REASON',
                'Credit and debit notes explain why they were issued.',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->type->isNote() && ($invoice->note ?? $invoice->instructionNote) === null
                    ? 'No reason given for the credit/debit note.'
                    : null,
            ),
            new CallableRule(
                'KSA-QR',
                'Simplified invoices carry the Phase 2 QR payload once signed.',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->isSimplified() && $invoice->qr === null
                    ? 'QR payload is empty; sign the invoice before handing it to the customer.'
                    : null,
            ),
            new CallableRule(
                'KSA-PIH-FORMAT',
                'The previous invoice hash is base64 of the previous 64-character hex digest.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $pih = $invoice->previousInvoiceHash;

                    if ($pih === null) {
                        return null;
                    }

                    try {
                        InvoiceHash::fromBase64OfHex($pih);

                        return null;
                    } catch (\Throwable) {
                        return 'Previous invoice hash is not base64 of a hex SHA-256 digest.';
                    }
                },
            ),
            new CallableRule(
                'KSA-SIMPLIFIED-BUYER-VAT',
                'A simplified invoice should not carry a buyer VAT number.',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->isSimplified() && $invoice->buyer?->vatNumber !== null
                    ? 'Simplified invoices are B2C; a buyer VAT number suggests this should be a standard invoice.'
                    : null,
            ),
            new CallableRule(
                'KSA-INVOICE-SUBTYPE-FLAGS',
                'The subtype prefix must match the invoice subclass in use.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => substr($invoice->typeNameAttribute(), 1, 1) === substr($invoice->subtype->value, 1, 1)
                    ? null
                    : 'Type name says standard/simplified differently from the invoice subtype (01 = standard, 02 = simplified).',
            ),
            new CallableRule(
                'KSA-ADJUSTMENT-SIGN',
                'Document-level charges add to the taxable base and allowances subtract from it.',
                Severity::Warning,
                static function (Invoice $invoice): ?string {
                    foreach ($invoice->adjustments as $adjustment) {
                        if ($adjustment->isCharge && $adjustment->amount->isZero()) {
                            return 'A zero-amount charge is present; ZATCA rejects empty adjustments.';
                        }
                    }

                    return null;
                },
            ),
            new CallableRule(
                'KSA-SIMPLIFIED-SUBTYPE',
                'Simplified invoices must use the 02xxxxx type name.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => $invoice->subtype === InvoiceSubtype::Simplified && substr($invoice->typeNameAttribute(), 1, 1) !== '2'
                    ? 'Simplified invoices use a type name whose second digit is 2 (02xxxxx).'
                    : null,
            ),
        ];
    }
}
