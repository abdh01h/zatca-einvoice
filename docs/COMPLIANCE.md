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
| QR encodings match ZATCA's SDK | tags 6‑7 are asserted to be ASCII base64; tag 8 is asserted to be raw DER starting `0x30` and 88 bytes long |
| Certificate bundles are supported | a two-certificate PEM is signed and both `ds:X509Certificate` elements are asserted |

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
- **Business process identifier.** `cbc:ProfileID` is `reporting:1.0` for standard and simplified
  documents (BR‑KSA‑EN16931‑01); `clearance:1.0` is a common mistake.
- **QR encodings.** Tags 6 and 7 carry ASCII base64 text; tags 8 and 9 carry raw DER bytes.
- **Transaction code.** Seven characters, `NNPNESB`: subtype (`01` standard, `02` simplified), then
  third-party, nominal, export, summary, self-billed flags. `InvoiceSubtype::withFlags()` builds it.

## Contradictions inside ZATCA's own material

These were found while building the library; the shipped SDK behaviour is followed in each case,
because that is what ZATCA's validators see in production.

| Point | What the documents say | What the SDK ships | Followed here |
|---|---|---|---|
| Credit-note root | "Credit note" message type | `<Invoice>` with code 381 | `<Invoice>` by default, native root opt-in |
| QR tag 8/9 | described as byte arrays of the key/signature | raw DER (not base64) | raw DER |
| QR tag 6 | "length of hash is 32 bytes" | 44 bytes of ASCII base64 | ASCII base64 |
| `SignaturePolicyIdentifier` | cardinality 1 ("shall contain") | absent from every sample | omitted |
| Business process | prose suggests a clearance profile | `reporting:1.0` everywhere | `reporting:1.0` |
| Supply date (BR‑KSA‑15) | required on standard tax invoices | absent from the standard sample | optional, not enforced |

## Not implemented

| Gap | Why | Workaround |
|---|---|---|
| Onboarding: CSR → compliance CSID → production CSID | requires the taxpayer's Fatoora portal OTP | sign with the CSID you obtain from the portal |
| Clearance (`/invoices/clearance/single`) and reporting (`/invoices/reporting/single`) | needs a live CSID and network policy decisions | POST `SignedDocument::xml()` yourself |
| `SignaturePolicyIdentifier` | absent from ZATCA's sample documents; optional in their schema | add it in a custom `ds:Object` if your counterparty requires it |
| C14N 1.1 | PHP's DOM ships C14N 1.0 and exclusive C14N only | digests are computed with the same serialiser that emits the document, so signing and verification agree; exotic `xml:base` documents are the theoretical edge case |
| XSD 1.1 / Schematron rule execution | ZATCA's business rules are Schematron, not XSD | `ZatcaRules` implements 26 of them; see `docs/rules.md` |
| Full BR‑KSA rule set | their compiled Schematron has hundreds of rules | the subset here covers the rules that break real integrations; unmapped ones are listed as unmapped |

## What "compliant" would mean

Passing these tests proves the document is well‑formed UBL, internally consistent, correctly hashed and
correctly signed. It does **not** prove ZATCA will accept it: that requires a sandbox CSID and a
clearance call. Treat this as the document layer of an integration, not the certification.
