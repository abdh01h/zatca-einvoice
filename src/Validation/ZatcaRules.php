<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

use Zatca\EInvoice\Crypto\InvoiceHash;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Domain\InvoiceCalculator;
use Zatca\EInvoice\Domain\TaxCategory;

/**
 * The ruleset enforced before a document is signed.
 *
 * Identifiers are ZATCA's own BR-KSA / BR-CO / BR-S identifiers wherever the
 * rule maps one-to-one onto a published rule; the few rules that cover gaps in
 * the published list keep a local `KSA-*` id so integrations can assert on them
 * without pretending they are something they are not. docs/rules.md carries the
 * mapping and the quoted rule text.
 *
 * Design note: anything the domain model already makes unrepresentable (no
 * seller without a VAT number, no empty invoice, no discount larger than its
 * line) is not re-checked here. These rules cover what the model cannot know.
 */
final class ZatcaRules
{
    /** @return list<Rule> */
    public static function default(): array
    {
        return [
            new CallableRule(
                'BR-KSA-09',
                'Seller address must contain street, building number, district, city, postal code and country code.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => $invoice->seller->address === null
                    ? 'Seller postal address is missing; Phase 2 rejects free-text or absent addresses.'
                    : null,
            ),
            new CallableRule(
                'BR-KSA-37',
                'The seller building number must be four digits.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $building = $invoice->seller->address?->buildingNumber;

                    return $building !== null && preg_match('/^\d{4}$/', $building) !== 1
                        ? sprintf('Seller building number "%s" is not four digits.', $building)
                        : null;
                },
            ),
            new CallableRule(
                'BR-KSA-66',
                'The seller postal code must be five digits.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $postal = $invoice->seller->address?->postalCode;

                    return $postal !== null && preg_match('/^\d{5}$/', $postal) !== 1
                        ? sprintf('Seller postal code "%s" is not five digits.', $postal)
                        : null;
                },
            ),
            new CallableRule(
                'BR-KSA-08',
                'The seller is identified by exactly one scheme-coded identifier (CRN in the common case).',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->seller->commercialRegistrationNumber === null
                    ? 'Seller has no commercial registration number (schemeID CRN).'
                    : null,
            ),
            new CallableRule(
                'BR-KSA-42',
                'A standard tax invoice must name the buyer.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => ! $invoice->isSimplified() && $invoice->buyer === null
                    ? 'Standard invoices require a buyer party; use the simplified subtype for walk-in customers.'
                    : null,
            ),
            new CallableRule(
                'BR-KSA-14',
                'A standard invoice buyer carries a VAT registration number (or another scheme-coded identifier).',
                Severity::Error,
                static fn (Invoice $invoice): ?string => ! $invoice->isSimplified() && $invoice->buyer?->vatNumber === null
                    ? 'Standard invoices require the buyer VAT number: 15 digits, starting and ending with 3.'
                    : null,
            ),
            new CallableRule(
                'BR-KSA-10',
                'A standard invoice buyer has a street, city and country code.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => ! $invoice->isSimplified() && $invoice->buyer !== null && $invoice->buyer->address === null
                    ? 'Buyer postal address is missing; simplified documents are exempt from this rule.'
                    : null,
            ),
            new CallableRule(
                'BR-KSA-63',
                'Saudi buyers supply building number, street, district, city and postal code.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $address = $invoice->buyer?->address;

                    if ($address === null || $address->countryCode !== 'SA') {
                        return null;
                    }

                    return preg_match('/^\d{4}$/', $address->buildingNumber) === 1
                        ? null
                        : 'Buyer building number must be four digits for Saudi addresses.';
                },
            ),
            new CallableRule(
                'BR-KSA-16',
                'Standard invoices carry a payment means code from UNTDID 4461.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    if ($invoice->isSimplified()) {
                        return null;
                    }

                    if ($invoice->paymentMeansCode === null) {
                        return 'Payment means code is required for standard invoices.';
                    }

                    return preg_match('/^\d{1,2}$/', $invoice->paymentMeansCode) === 1
                        ? null
                        : sprintf('Payment means code "%s" is not a UNTDID 4461 code.', $invoice->paymentMeansCode);
                },
            ),
            new CallableRule(
                'BR-KSA-06',
                'The invoice transaction code is seven characters: NN (subtype), then third-party, nominal, export, summary and self-billed flags.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => preg_match('/^0[12][01]{5}$/', $invoice->typeNameAttribute()) === 1
                    ? null
                    : sprintf('Type name "%s" does not follow the NNPNESB structure.', $invoice->typeNameAttribute()),
            ),
            new CallableRule(
                'BR-KSA-07',
                'Self-billing is not allowed on export invoices.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $name = $invoice->typeNameAttribute();

                    return substr($name, 4, 1) === '1' && substr($name, 6, 1) === '1'
                        ? 'Type name sets both the export flag and the self-billed flag.'
                        : null;
                },
            ),
            new CallableRule(
                'BR-KSA-31',
                'Simplified documents accept only the third-party, nominal and summary flags.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $name = $invoice->typeNameAttribute();

                    if (substr($name, 1, 1) !== '2') {
                        return null;
                    }

                    return substr($name, 4, 1) === '1' || substr($name, 6, 1) === '1'
                        ? 'Simplified documents must not set the export or self-billed flags.'
                        : null;
                },
            ),
            new CallableRule(
                'KSA-INVOICE-NUMBER',
                'cbc:ID carries the seller\'s own sequential document number.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => $invoice->invoiceNumber === null || trim($invoice->invoiceNumber) === ''
                    ? 'Invoice number is missing; cbc:ID must be the seller\'s sequential document number.'
                    : null,
            ),
            new CallableRule(
                'BR-KSA-04',
                'The issue date may not be in the future.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
                    $issued = $invoice->issueDate->setTimezone(new \DateTimeZone('UTC'));

                    return $issued->format('Y-m-d') > $today
                        ? sprintf('Issue date %s is in the future.', $issued->format('Y-m-d'))
                        : null;
                },
            ),
            new CallableRule(
                'BR-CO-15',
                'Tax-inclusive total equals tax-exclusive total plus tax.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $totals = InvoiceCalculator::totals($invoice);

                    return $totals->taxExclusiveAmount->plus($totals->taxAmount)->equals($totals->taxInclusiveAmount)
                        ? null
                        : 'Totals do not reconcile: tax-exclusive + tax differs from tax-inclusive.';
                },
            ),
            new CallableRule(
                'BR-CO-16',
                'Payable amount equals tax-inclusive total minus prepaid amount.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    $totals = InvoiceCalculator::totals($invoice);
                    $expected = $totals->taxInclusiveAmount->minus($totals->prepaidAmount);

                    return $expected->equals($totals->payableAmount)
                        ? null
                        : 'Payable amount does not reconcile with tax-inclusive total and prepaid amount.';
                },
            ),
            new CallableRule(
                'BR-S-09',
                'Each VAT breakdown equals taxable amount x rate, rounded to two decimals.',
                Severity::Error,
                static function (Invoice $invoice): ?string {
                    foreach (InvoiceCalculator::totals($invoice)->taxSubtotals as $subtotal) {
                        $expected = $subtotal->taxableAmount->percentage($subtotal->rateBasisPoints);

                        // one halala of tolerance: tax is rounded per line and then
                        // summed, which can drift by a halala against a breakdown
                        // computed from the aggregate base
                        if (abs($expected->minorUnits - $subtotal->taxAmount->minorUnits) > 1) {
                            return sprintf(
                                'VAT breakdown %s/%s%% carries %s tax but %s is expected.',
                                $subtotal->category->value,
                                $subtotal->rateBasisPoints / 100,
                                $subtotal->taxAmount->toDecimal(),
                                $expected->toDecimal(),
                            );
                        }
                    }

                    return null;
                },
            ),
            new CallableRule(
                'BR-KSA-23',
                'Zero-rated, exempt and out-of-scope lines carry a VATEX-SA exemption reason code.',
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
                'BR-KSA-83',
                'Zero-rated, exempt and out-of-scope lines also carry the exemption reason text.',
                Severity::Warning,
                static function (Invoice $invoice): ?string {
                    foreach ($invoice->lines as $line) {
                        if ($line->taxCategory !== TaxCategory::Standard && ($line->exemptionReason === null || trim($line->exemptionReason) === '')) {
                            return sprintf('Line "%s" has an exemption code but no reason text.', $line->name);
                        }
                    }

                    return null;
                },
            ),
            new CallableRule(
                'BR-KSA-17',
                'Credit and debit notes state the reason they were issued.',
                Severity::Error,
                static fn (Invoice $invoice): ?string => $invoice->type->isNote() && $invoice->note === null
                    ? 'Credit and debit notes must contain the reason for issuing (cbc:Note).'
                    : null,
            ),
            new CallableRule(
                'BR-KSA-56',
                'Credit and debit notes reference the original invoice by UUID.',
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
                'BR-KSA-26',
                'The previous invoice hash is base64 of the previous SHA-256 digest, in either accepted encoding.',
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
                        // fall through to the 44-character raw-digest form
                    }

                    try {
                        InvoiceHash::fromBase64($pih);

                        return null;
                    } catch (\Throwable) {
                        return 'Previous invoice hash is neither base64 of a hex digest (88 chars) nor base64 of a raw digest (44 chars).';
                    }
                },
            ),
            new CallableRule(
                'BR-KSA-60',
                'Simplified documents carry the cryptographic stamp and QR payload once signed.',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->isSimplified() && $invoice->qr === null
                    ? 'QR payload is empty; sign the invoice before handing it to the customer.'
                    : null,
            ),
            new CallableRule(
                'BR-KSA-EN16931-02',
                'Tax amounts are expressed in SAR.',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->currency->value === 'SAR'
                    ? null
                    : sprintf('Document currency is %s; ZATCA also requires the tax amounts in SAR.', $invoice->currency->value),
            ),
            new CallableRule(
                'KSA-SIMPLIFIED-BUYER-VAT',
                'A simplified invoice is a B2C document and should not carry a buyer VAT number.',
                Severity::Warning,
                static fn (Invoice $invoice): ?string => $invoice->isSimplified() && $invoice->buyer?->vatNumber !== null
                    ? 'Simplified invoices are B2C; a buyer VAT number suggests this should be a standard invoice.'
                    : null,
            ),
            new CallableRule(
                'KSA-ZERO-CHARGE',
                'Zero-amount document adjustments are rejected because ZATCA treats them as empty elements.',
                Severity::Warning,
                static function (Invoice $invoice): ?string {
                    foreach ($invoice->adjustments as $adjustment) {
                        if ($adjustment->amount->isZero()) {
                            return 'A zero-amount allowance or charge is present.';
                        }
                    }

                    return null;
                },
            ),
        ];
    }
}
