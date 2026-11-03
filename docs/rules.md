# Business rules

`InvoiceValidator::withDefaults()` runs the rules below. Identifiers are ZATCA's own where the rule
maps one-to-one onto a published rule (`BR-KSA-*`, `BR-CO-*`, `BR-S-*`); the remaining rules carry a
local `KSA-*` id and say plainly that they cover a gap in the published list. Quoted text is from
ZATCA's *Electronic Invoice XML Implementation Standard* §13.3 and the compiled Schematron (SVRL).

## ZATCA rules

| Rule | Severity | Checks | ZATCA wording (abridged) |
|---|---|---|---|
| `BR-KSA-04` | error | issue date is not in the future | "The document issue date (BT-2) must be less or equal to the current date." |
| `BR-KSA-06` | error | transaction code follows `NNPNESB` | "The invoice transaction code (KSA-2) must exist and respect the following structure: NNPNESB" |
| `BR-KSA-07` | error | export + self-billed flags are not combined | "Self-billing is not allowed … for export invoices." |
| `BR-KSA-08` | warning | seller carries a scheme-coded identifier (CRN) | "The seller identification (BT-29) must exist only once with one of the scheme ID …" |
| `BR-KSA-09` | error | seller address is structured | "Seller address must contain street name, building number, postal code, city, District, country code." |
| `BR-KSA-10` | error | standard-invoice buyer has street, city, country | "Buyer address must contain a street, city, country code. This rule does not apply on the simplified tax invoices …" |
| `BR-KSA-14` | error | standard-invoice buyer has an identifier | "The buyer identification (BT-46), required only if buyer is not VAT registered …" |
| `BR-KSA-16` | error | payment means code present and well formed | "Payment means code (BT-81) … must contain one of the values from subset of UNTDID 4461 code list" |
| `BR-KSA-17` | error | notes state a reason in `PaymentMeans/InstructionNote` | "Debit and credit note … must contain the reason (KSA-10) for this invoice type issuing." |
| `BR-KSA-23` | error | Z/E/O lines carry a `VATEX-SA-*` code | "A VAT breakdown … 'Exempt from VAT' shall have a VAT exemption reason code (BT-121)." |
| `BR-KSA-26` | error | previous hash is a base64 SHA-256 digest | "If the invoice contains the previous invoice hash (KSA-13), this hash must be base64 encoded SHA256" |
| `BR-KSA-31` | error | simplified documents set only allowed flags | "For simplified tax invoices … only the following are accepted: third party … nominal supply … and summary transactions." |
| `BR-KSA-37` | error | seller building number is four digits | "The seller address building number must contain 4 digits." |
| `BR-KSA-42` | error | standard invoice names the buyer | "The buyer name (BT-44) must be present in the tax invoice …" |
| `BR-KSA-56` | error | notes reference the original invoice (number or UUID) | "For credit notes … and debit notes …, the billing reference ID (BT-25) is mandatory." |
| `BR-KSA-60` | warning | simplified documents carry a QR payload | "Cryptographic stamp (KSA-15) must exist in simplified tax invoices …" |
| `BR-KSA-63` | error | Saudi buyer has a four-digit building number | "If the buyer country code is 'SA', then these fields are mandatory …" |
| `BR-KSA-66` | error | seller postal code is five digits | "Seller postal code (BT-38) must be 5 digits." |
| `BR-KSA-83` | warning | Z/E/O lines carry exemption reason text | "… shall have a VAT exemption (or exception) reason text (BT-120) with one of the values from paragraph 11." |
| `BR-KSA-EN16931-02` | warning | document currency is SAR | "VAT accounting currency code (BT-6) must be SAR." |
| `BR-KSA-EN16931-09` | error | foreign-currency invoices carry the SAR tax total | "Only one tax total without tax subtotals must be provided when tax currency code is provided." |
| `BR-S-09` | error | each breakdown equals base × rate | "The VAT category tax amount … shall equal the VAT category taxable amount multiplied by the VAT category rate / 100, rounded to two decimals." |

## Local rules (gap fillers)

| Rule | Severity | Checks |
|---|---|---|
| `KSA-INVOICE-NUMBER` | error | `cbc:ID` carries a non-empty sequential document number |
| `KSA-SIMPLIFIED-BUYER-VAT` | warning | a simplified (B2C) document does not carry a buyer VAT number |
| `KSA-ZERO-CHARGE` | warning | no zero-amount document-level allowance or charge |

Rules that the domain model already makes impossible — seller without VAT, empty invoice, discount
larger than its line, line currency differing from the document currency, a note without a billing
reference — are not repeated here. They throw at construction time instead.

## Document-level checks

`DocumentValidator` reconciles the numbers inside an already-built document — including documents that
came from another system — and reports `BR-CO-10` (sum of line nets), `BR-CO-13` (allowances and
charges), `BR-CO-15` (inclusive total) and `BR-CO-16` (payable amount). The invoice-level ruleset does
not repeat those sums, because `InvoiceCalculator` computes them once and an aggregate cannot disagree
with itself.

## Tolerance

`BR-S-09` allows one halala of drift per VAT breakdown. Tax is rounded per line and then summed;
a breakdown recomputed from the aggregate base can therefore differ by a halala on invoices with many
small lines. ZATCA's own SDK rounds per line, so the tolerance reflects arithmetic reality rather than
a weaker check.

## Writing your own

```php
use Zatca\EInvoice\Validation\{CallableRule, InvoiceValidator, Severity, ZatcaRules};

$validator = new InvoiceValidator([
    ...ZatcaRules::default(),
    new CallableRule(
        'ACME-ORDER-REF',
        'Every invoice must reference our ERP order',
        Severity::Error,
        fn ($invoice) => $invoice->orderReference === null ? 'Order reference missing' : null,
    ),
]);
```
