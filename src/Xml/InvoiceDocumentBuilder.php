<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Xml;

use Zatca\EInvoice\Domain\AllowanceCharge;
use Zatca\EInvoice\Domain\Address;
use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Domain\LineItem;
use Zatca\EInvoice\Domain\Party;
use Zatca\EInvoice\Domain\TaxSubtotal;

/**
 * Builds the unsigned UBL 2.1 document.
 *
 * Element order is the UBL sequence, not a stylistic choice: the XSD is a
 * sequence and ZATCA rejects documents whose children are out of order.
 * Signing happens afterwards ({@see \Zatca\EInvoice\Crypto\InvoiceSigner}),
 * because the digest must be computed over the document *without* the
 * extension block and the QR reference.
 */
final class InvoiceDocumentBuilder
{
    private const SIG_SCHEME_ID = 'UN/ECE 5305';
    private const SIG_SCHEME_AGENCY = '6';
    private const TAX_SCHEME_ID = 'UN/ECE 5153';

    public function __construct(private readonly RootElement $root = RootElement::Invoice)
    {
    }

    public function root(): RootElement
    {
        return $this->root;
    }

    public function build(Invoice $invoice): \DOMDocument
    {
        $xml = new XmlBuilder($this->root->value, $this->root->namespace(), [
            'cac' => Namespaces::CAC,
            'cbc' => Namespaces::CBC,
            'ext' => Namespaces::EXT,
        ]);

        $totals = $invoice->totals();

        // BR-KSA-EN16931-01: the business process identifier is reporting:1.0
        // for standard and simplified documents alike (all ZATCA samples agree).
        $xml->add($xml->root, 'cbc:ProfileID', 'reporting:1.0');
        $xml->add($xml->root, 'cbc:ID', $invoice->documentId());
        $xml->add($xml->root, 'cbc:UUID', $invoice->uuid->value);
        $xml->add($xml->root, 'cbc:IssueDate', $invoice->issueDate->format('Y-m-d'));
        $xml->add($xml->root, 'cbc:IssueTime', ($invoice->issueTime ?? $invoice->issueDate)->format('H:i:s'));

        $xml->add($xml->root, $this->root->typeCodeElement(), $invoice->type->value, ['name' => $invoice->typeNameAttribute()]);

        if ($invoice->note !== null) {
            $xml->add($xml->root, 'cbc:Note', $invoice->note);
        }

        $xml->add($xml->root, 'cbc:DocumentCurrencyCode', $invoice->currency->value);
        $xml->add($xml->root, 'cbc:TaxCurrencyCode', 'SAR');

        if ($invoice->orderReference !== null) {
            $orderReference = $xml->add($xml->root, 'cac:OrderReference');
            $xml->add($orderReference, 'cbc:ID', $invoice->orderReference);
        }

        if ($invoice->type->isNote()) {
            $this->billingReference($xml, $invoice);
        }

        $this->additionalDocumentReferences($xml, $invoice);

        $supplier = $xml->add($xml->root, 'cac:AccountingSupplierParty');
        $this->party($xml, $supplier, $invoice->seller, isSeller: true);

        // UBL requires cac:AccountingCustomerParty on an Invoice root even for
        // B2C sales. ZATCA's own simplified samples emit the element with empty
        // party details, so an anonymous buyer is represented the same way.
        $customer = $xml->add($xml->root, 'cac:AccountingCustomerParty');

        if ($invoice->buyer !== null) {
            $this->party($xml, $customer, $invoice->buyer, isSeller: false);
        } else {
            $party = $xml->add($customer, 'cac:Party');
            $taxScheme = $xml->add($party, 'cac:PartyTaxScheme');
            $scheme = $xml->add($taxScheme, 'cac:TaxScheme');
            $xml->add($scheme, 'cbc:ID', 'VAT');
        }

        if ($invoice->deliveryDate !== null) {
            $delivery = $xml->add($xml->root, 'cac:Delivery');
            $xml->add($delivery, 'cbc:ActualDeliveryDate', $invoice->deliveryDate->format('Y-m-d'));
        }

        if ($invoice->paymentMeansCode !== null || $invoice->instructionNote !== null) {
            $paymentMeans = $xml->add($xml->root, 'cac:PaymentMeans');

            if ($invoice->paymentMeansCode !== null) {
                $xml->add($paymentMeans, 'cbc:PaymentMeansCode', $invoice->paymentMeansCode);
            }

            // BR-KSA-17: the reason for a credit/debit note is KSA-10, which
            // ZATCA maps to PaymentMeans/InstructionNote (see its own samples).
            if ($invoice->instructionNote !== null) {
                $xml->add($paymentMeans, 'cbc:InstructionNote', $invoice->instructionNote);
            }
        }

        foreach ($invoice->adjustments as $adjustment) {
            $this->allowanceCharge($xml, $xml->root, $adjustment);
        }

        $taxTotal = $xml->add($xml->root, 'cac:TaxTotal');
        $xml->money($taxTotal, 'cbc:TaxAmount', $totals->taxAmount);

        foreach ($totals->taxSubtotals as $subtotal) {
            $this->taxSubtotal($xml, $taxTotal, $subtotal);
        }

        $monetaryTotal = $xml->add($xml->root, 'cac:LegalMonetaryTotal');
        $xml->money($monetaryTotal, 'cbc:LineExtensionAmount', $totals->lineExtensionAmount);
        $xml->money($monetaryTotal, 'cbc:TaxExclusiveAmount', $totals->taxExclusiveAmount);
        $xml->money($monetaryTotal, 'cbc:TaxInclusiveAmount', $totals->taxInclusiveAmount);
        $xml->money($monetaryTotal, 'cbc:AllowanceTotalAmount', $totals->allowanceTotalAmount);
        $xml->money($monetaryTotal, 'cbc:ChargeTotalAmount', $totals->chargeTotalAmount);
        $xml->money($monetaryTotal, 'cbc:PrepaidAmount', $totals->prepaidAmount);
        $xml->money($monetaryTotal, 'cbc:PayableAmount', $totals->payableAmount);

        foreach ($invoice->lines as $index => $line) {
            $this->line($xml, $this->root->lineElement(), $this->root->quantityElement(), $index + 1, $line);
        }

        return $xml->document;
    }

    private function billingReference(XmlBuilder $xml, Invoice $invoice): void
    {
        $reference = $xml->add($xml->root, 'cac:BillingReference');
        $documentReference = $xml->add($reference, 'cac:InvoiceDocumentReference');

        // DocumentReferenceType requires cbc:ID before cbc:UUID. The original
        // invoice number is the natural value; the UUID is the fallback so the
        // document stays schema-valid when only the UUID is known.
        // ZATCA's own credit-note sample references the original invoice by
        // number in cbc:ID and carries no UUID, so the UUID is emitted only
        // when the caller supplied one.
        $xml->add(
            $documentReference,
            'cbc:ID',
            $invoice->billingReferenceNumber ?? $invoice->billingReferenceId ?? 'unknown',
        );

        if ($invoice->billingReferenceId !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $invoice->billingReferenceId) === 1) {
            $xml->add($documentReference, 'cbc:UUID', $invoice->billingReferenceId);
        }

        if ($invoice->billingReferenceIssueDate !== null) {
            $xml->add($documentReference, 'cbc:IssueDate', $invoice->billingReferenceIssueDate->format('Y-m-d'));
        }
    }

    private function additionalDocumentReferences(XmlBuilder $xml, Invoice $invoice): void
    {
        $icv = $xml->add($xml->root, 'cac:AdditionalDocumentReference');
        $xml->add($icv, 'cbc:ID', 'ICV');
        $xml->add($icv, 'cbc:UUID', (string) $invoice->counterValue);

        $pih = $xml->add($xml->root, 'cac:AdditionalDocumentReference');
        $xml->add($pih, 'cbc:ID', 'PIH');
        $attachment = $xml->add($pih, 'cac:Attachment');
        $xml->add(
            $attachment,
            'cbc:EmbeddedDocumentBinaryObject',
            $invoice->previousInvoiceHash ?? \Zatca\EInvoice\Crypto\InvoiceHash::initialPreviousHash(),
            ['mimeCode' => 'text/plain'],
        );
    }

    private function party(XmlBuilder $xml, \DOMElement $parent, Party $party, bool $isSeller): void
    {
        $partyElement = $xml->add($parent, 'cac:Party');

        if ($party->commercialRegistrationNumber !== null) {
            $identification = $xml->add($partyElement, 'cac:PartyIdentification');
            $xml->add($identification, 'cbc:ID', $party->commercialRegistrationNumber, ['schemeID' => 'CRN']);
        }

        if ($party->address instanceof Address) {
            $address = $xml->add($partyElement, 'cac:PostalAddress');
            $xml->add($address, 'cbc:StreetName', $party->address->streetName);
            $xml->add($address, 'cbc:BuildingNumber', $party->address->buildingNumber);

            if ($party->address->plotIdentification !== null) {
                $xml->add($address, 'cbc:PlotIdentification', $party->address->plotIdentification);
            }

            $xml->add($address, 'cbc:CitySubdivisionName', $party->address->citySubdivision);
            $xml->add($address, 'cbc:CityName', $party->address->city);
            $xml->add($address, 'cbc:PostalZone', $party->address->postalCode);
            $country = $xml->add($address, 'cac:Country');
            $xml->add($country, 'cbc:IdentificationCode', $party->address->countryCode);
        }

        $taxScheme = $xml->add($partyElement, 'cac:PartyTaxScheme');

        if ($party->vatNumber !== null) {
            $xml->add($taxScheme, 'cbc:CompanyID', $party->vatNumber->value);
        }

        $scheme = $xml->add($taxScheme, 'cac:TaxScheme');
        $xml->add($scheme, 'cbc:ID', 'VAT');

        $legalEntity = $xml->add($partyElement, 'cac:PartyLegalEntity');
        $xml->add($legalEntity, 'cbc:RegistrationName', $party->registrationName);

        // kept for symmetry with the samples: sellers are identified by CRN,
        // buyers by their national VAT registration in PartyTaxScheme
        unset($isSeller);
    }

    private function allowanceCharge(XmlBuilder $xml, \DOMElement $parent, AllowanceCharge $adjustment): void
    {
        $element = $xml->add($parent, 'cac:AllowanceCharge');
        $xml->add($element, 'cbc:ChargeIndicator', $adjustment->isCharge ? 'true' : 'false');
        $xml->add($element, 'cbc:AllowanceChargeReason', $adjustment->reason);
        $xml->money($element, 'cbc:Amount', $adjustment->amount);
        $this->taxCategory($xml, $element, $adjustment->taxCategory->value, $adjustment->taxRateBasisPoints(), $adjustment->exemptionReasonCode);
    }

    private function taxSubtotal(XmlBuilder $xml, \DOMElement $parent, TaxSubtotal $subtotal): void
    {
        $element = $xml->add($parent, 'cac:TaxSubtotal');
        $xml->money($element, 'cbc:TaxableAmount', $subtotal->taxableAmount);
        $xml->money($element, 'cbc:TaxAmount', $subtotal->taxAmount);
        $this->taxCategory($xml, $element, $subtotal->category->value, $subtotal->rateBasisPoints);
    }

    private function taxCategory(XmlBuilder $xml, \DOMElement $parent, string $category, int $rateBasisPoints, ?string $exemptionReasonCode = null, string $elementName = 'cac:TaxCategory', ?string $exemptionReasonText = null): void
    {
        $taxCategory = $xml->add($parent, $elementName);
        $xml->add($taxCategory, 'cbc:ID', $category, [
            'schemeID' => self::SIG_SCHEME_ID,
            'schemeAgencyID' => self::SIG_SCHEME_AGENCY,
        ]);
        $xml->add($taxCategory, 'cbc:Percent', number_format($rateBasisPoints / 100, 2, '.', ''));

        if ($exemptionReasonCode !== null) {
            $xml->add($taxCategory, 'cbc:TaxExemptionReasonCode', $exemptionReasonCode);
        }

        if ($exemptionReasonText !== null) {
            $xml->add($taxCategory, 'cbc:TaxExemptionReason', $exemptionReasonText);
        }

        $scheme = $xml->add($taxCategory, 'cac:TaxScheme');
        $xml->add($scheme, 'cbc:ID', 'VAT', [
            'schemeID' => self::TAX_SCHEME_ID,
            'schemeAgencyID' => self::SIG_SCHEME_AGENCY,
        ]);
    }

    private function line(XmlBuilder $xml, string $lineElement, string $quantityElement, int $position, LineItem $item): void
    {
        $line = $xml->add($xml->root, $lineElement);
        $xml->add($line, 'cbc:ID', (string) $position);
        $xml->add($line, $quantityElement, $item->quantity->toDecimal(), ['unitCode' => $item->quantity->unitCode]);
        $xml->money($line, 'cbc:LineExtensionAmount', $item->taxableAmount());

        if ($item->discount instanceof \Zatca\EInvoice\Domain\Money && ! $item->discount->isZero()) {
            $allowance = $xml->add($line, 'cac:AllowanceCharge');
            $xml->add($allowance, 'cbc:ChargeIndicator', 'false');
            $xml->add($allowance, 'cbc:AllowanceChargeReason', $item->discountReason ?? 'Discount');
            $xml->money($allowance, 'cbc:Amount', $item->discount);
        }

        $taxTotal = $xml->add($line, 'cac:TaxTotal');
        $xml->money($taxTotal, 'cbc:TaxAmount', $item->taxAmount());
        $xml->money($taxTotal, 'cbc:RoundingAmount', $item->totalAmount());

        $itemElement = $xml->add($line, 'cac:Item');

        if ($item->sellerItemId !== null) {
            $identification = $xml->add($itemElement, 'cac:SellersItemIdentification');
            $xml->add($identification, 'cbc:ID', $item->sellerItemId);
        }

        $xml->add($itemElement, 'cbc:Name', $item->name);
        $this->taxCategory(
            $xml,
            $itemElement,
            $item->taxCategory->value,
            $item->taxRateBasisPoints(),
            $item->exemptionReasonCode,
            'cac:ClassifiedTaxCategory',
            $item->exemptionReason,
        );

        $price = $xml->add($line, 'cac:Price');
        $xml->money($price, 'cbc:PriceAmount', $item->unitPrice);
    }
}
