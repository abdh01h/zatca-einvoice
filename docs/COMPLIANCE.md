# Compliance notes

What this library claims, what it has actually been tested against, and what it deliberately does not
do. Written for someone who has to decide whether to put it in front of ZATCA.

## Verified in this repository

| Claim | How it is verified |
|---|---|
| Generated documents are valid UBL 2.1 | `SchemaValidator` runs libxml against the vendored OASIS schemas; `tests/Feature/InvoiceDocumentTest.php` asserts it for every document type |
| The vendored schemas are the real thing | `tests/Feature/SchemaValidationTest.php` pins `maindoc/UBL-Invoice-2.1.xsd` to `sha256 40fae8cb…4edf4` (the OASIS 2.1 OS release) |
| ZATCA's own samples validate against those schemas | all five SDK samples in `tests/fixtures/zatca-samples/` are validated on every run |
| The invoice hash is stable across serialisation | the signed XML is re‑parsed from bytes and re‑hashed; the result is compared with `ds:DigestValue` |
| The ECDSA signature is valid | `openssl_verify` over canonicalised `ds:SignedInfo` with the signing certificate |
| PIH chaining matches ZATCA's convention | first invoice equals the published `NWZlY2Vi…NTdlOQ==` constant; chained invoices equal `base64(hex(previous))` |
| QR payloads are well formed | encoded and decoded round‑trip, tag lengths asserted, UTF‑8 (Arabic) values covered |

## Conventions this library encodes

- **Two hash encodings.** `ds:DigestValue` and QR tag 6: base64 of the raw 32‑byte digest. PIH:
  base64 of the lowercase 64‑character hex digest. Both are exposed from `InvoiceHash` so an
  integration cannot accidentally use the wrong one.
- **Excluded subtrees.** The invoice digest is computed with `ext:UBLExtensions`, `cac:Signature` and
  `cac:AdditionalDocumentReference[cbc:ID='QR']` removed — the same three XPath transforms written into
  `ds:SignedInfo`.
- **Document roots.** ZATCA's samples use `<Invoice>` for 381 and 383. `RootElement::CreditNote` and
  `RootElement::DebitNote` exist for counterparties that expect native roots.
- **`cac:AccountingCustomerParty` is required by UBL** even for B2C sales, so a simplified invoice emits
  the element with an anonymous party rather than omitting it.
- **Key curve.** ZATCA requires secp256k1. `SigningCredentials` refuses any other curve at construction
  time instead of producing a document that fails later.

## Not implemented

| Gap | Why | Workaround |
|---|---|---|
| Onboarding: CSR → compliance CSID → production CSID | requires the taxpayer's Fatoora portal OTP | sign with the CSID you obtain from the portal |
| Clearance (`/invoices/clearance/single`) and reporting (`/invoices/reporting/single`) | needs a live CSID and network policy decisions | POST `SignedDocument::xml()` yourself |
| `SignaturePolicyIdentifier` | absent from ZATCA's sample documents; optional in their schema | add it in a custom `ds:Object` if your counterparty requires it |
| C14N 1.1 | PHP's DOM ships C14N 1.0 and exclusive C14N only | digests are computed with the same serialiser that emits the document, so signing and verification agree; exotic `xml:base` documents are the theoretical edge case |
| XSD 1.1 / Schematron rule execution | ZATCA's business rules are Schematron, not XSD | `ZatcaRules` implements the subset modelled here; see `docs/rules.md` |

## What "compliant" would mean

Passing these tests proves the document is well‑formed UBL, internally consistent, correctly hashed and
correctly signed. It does **not** prove ZATCA will accept it: that requires a sandbox CSID and a
clearance call. Treat this as the document layer of an integration, not the certification.
