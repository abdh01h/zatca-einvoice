# Business rules

`InvoiceValidator::withDefaults()` runs the rules below. Identifiers are local and stable so an
integration can assert on them in its own tests. The right‑hand column maps to ZATCA's published
BR‑KSA rules where a one‑to‑one match has been confirmed; blank means "not yet mapped", not "no rule".

| Rule | Severity | Checks | ZATCA BR |
|---|---|---|---|
| `KSA-SELLER-ADDRESS` | error | seller has a structured national address | BR‑KSA‑66 (seller postal code) |
| `KSA-SELLER-CRN` | warning | seller commercial registration number present | |
| `KSA-BUYER-REQUIRED-STANDARD` | error | standard invoices identify a buyer | |
| `KSA-BUYER-VAT-STANDARD` | error | standard invoices carry the buyer VAT number | |
| `KSA-BUYER-ADDRESS-STANDARD` | error | SA buyers carry the full address | BR‑KSA‑63 |
| `KSA-PAYMENT-MEANS` | error | standard invoices carry a payment means code | |
| `KSA-INVOICE-NUMBER` | error | `cbc:ID` is present and non‑empty | |
| `KSA-TYPE-NAME` | error | seven‑digit type name (`0` + `1`/`2` + five flags) | |
| `KSA-CURRENCY-SAR` | warning | document currency is SAR, or a SAR tax total is added | |
| `KSA-ISSUE-DATE` | warning | issue date is not more than five days ahead | |
| `KSA-PREPAID` | error | prepaid amount ≤ tax‑inclusive total | |
| `KSA-TAX-SUBTOTALS` | error | tax subtotals sum to the invoice tax | |
| `KSA-STANDARD-RATE` | warning | standard category is 15% unless explicitly overridden | |
| `KSA-EXEMPTION-CODE` | error | Z/E/O lines carry a `VATEX-SA-*` reason code | |
| `KSA-NOTE-REFERENCE` | error | credit/debit notes reference the original UUID | |
| `KSA-NOTE-REASON` | warning | notes explain why they were issued | |
| `KSA-QR` | warning | simplified documents carry a QR payload once signed | |
| `KSA-PIH-FORMAT` | error | previous hash is base64 of a hex digest | |
| `KSA-SIMPLIFIED-BUYER-VAT` | warning | simplified invoices do not look like B2B documents | |
| `KSA-INVOICE-SUBTYPE-FLAGS` | error | type name agrees with the subtype | |
| `KSA-SIMPLIFIED-SUBTYPE` | error | simplified documents use `02xxxxx` | |
| `KSA-ADJUSTMENT-SIGN` | warning | no zero‑amount document adjustments | |

Rules that the domain model already makes impossible — seller without VAT, empty invoice, discount
larger than the line, line currency differing from the document currency — are not repeated here.

## Writing your own

```php
use Zatca\EInvoice\Validation\{CallableRule, InvoiceValidator, Severity};

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
