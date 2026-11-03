# ZATCA e-invoicing toolkit for PHP

Generate, validate and cryptographically sign Saudi ZATCA (Fatoora) Phase‑2 e‑invoices in plain PHP:
UBL 2.1 XML, Phase‑1/Phase‑2 QR codes, invoice hashing, XAdES B‑B signatures and a business‑rule validator.

[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777bb4)](composer.json)
[![Tests](https://img.shields.io/badge/tests-83%20passing-brightgreen)](tests)
[![Licence](https://img.shields.io/badge/licence-MIT-blue)](LICENSE)

---

## Why this exists

Every ZATCA integration I have worked on failed first on the same handful of things — and none of them
appear in a stack trace:

1. **The invoice hash has two encodings.** `ds:DigestValue` and QR tag 6 carry base64 of the *raw*
   32‑byte SHA‑256. The previous‑invoice‑hash (PIH) chain carries base64 of the *lowercase hex* digest —
   an 88‑character string. The first invoice in a chain uses `base64(hex(sha256("0")))`. Mixing them up
   produces a document ZATCA rejects with no useful message.
2. **Pretty printing breaks the signature.** Indentation inserts whitespace text nodes, which are part
   of the canonical form. A document that validates against the XSD can still fail clearance because
   its hash no longer matches. This library returns the exact signed bytes and keeps an indented copy
   for humans, labelled as not submittable.
3. **The QR code mixes encodings inside one payload.** Tags 6 and 7 are the *ASCII text* of the base64
   hash and signature; tags 8 and 9 are the *raw DER bytes* of the public key and the stamp. A QR that
   base64-encodes tag 8 is 132 bytes longer than ZATCA's own output and will not validate.
4. **381/383 are `<Invoice>` roots.** ZATCA's own SDK samples ship credit and debit notes as
   `<Invoice>` with `cbc:InvoiceTypeCode` 381/383, not as `<CreditNote>`/`<DebitNote>`. Emitting the
   native roots is supported here, but it is not the default.
5. **`cbc:ProfileID` is `reporting:1.0` for everything.** Not `clearance:1.0` for B2B — rule
   BR‑KSA‑EN16931‑01 is explicit, and all five SDK samples agree.
6. **`xades:CertDigest` hashes the certificate's base64 text, not its DER.** Hash the DER and you get
   a perfectly valid-looking digest that ZATCA rejects. The same "hex, then base64" wrapping applies
   to the `SignedProperties` digest.

This package encodes those rules once, with tests that re‑parse the output and verify it the way ZATCA
will.

## What it does

- **UBL 2.1 documents** — standard and simplified invoices, credit and debit notes, document‑level
  allowances and charges, multi‑rate tax subtotals.
- **Exact money** — every amount is an integer number of halalas; no float ever touches a total.
  Rounding is half‑up, once per line, and the calculator asserts its own invariants.
- **Phase‑1 QR** — the five TLV tags required at generation time.
- **Phase‑2 signing** — invoice hash, XAdES B‑B enveloped signature over ECDSA‑SHA256/secp256k1,
  certificate digest, issuer serial, and the nine‑tag QR payload.
- **Business rules** — 24 rules keyed to ZATCA's own BR‑KSA / BR‑CO / BR‑S identifiers, plus XSD
  validation against the vendored OASIS UBL 2.1 schemas.
- **Zero runtime dependencies** — `ext-dom`, `ext-mbstring`, `ext-openssl` only. No BCMath, no GMP.

## Install

```bash
composer require abdh01h/zatca-einvoice
```

Until the package is on Packagist, add the repository and require it as a VCS dependency:

```json
{
  "repositories": [{"type": "vcs", "url": "https://github.com/abdh01h/zatca-einvoice"}],
  "require": {"abdh01h/zatca-einvoice": "dev-main"}
}
```

## Quick start

```php
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Crypto\SigningCredentials;
use Zatca\EInvoice\Domain\{Address, Invoice, InvoiceSubtype, InvoiceType, LineItem, Money, Party, Quantity, VatNumber};

$seller = new Party(
    registrationName: 'Acme Widgets Ltd',
    address: new Address('Prince Sultan Street', '3242', 'Al Olaya', 'Riyadh', '12212', 'SA', '4323'),
    vatNumber: new VatNumber('311111111101113'),
    commercialRegistrationNumber: '1010101010',
);

$invoice = Invoice::draft(
    InvoiceType::Invoice,
    InvoiceSubtype::Standard,
    new DateTimeImmutable('now', new DateTimeZone('UTC')),
    $seller,
    new Party('Beta Trading Co', new Address('King Fahd Road', '1111', 'Al Malaz', 'Riyadh', '11411', 'SA'), new VatNumber('300000000000003')),
    [
        new LineItem('Consulting hours', Quantity::of('12.5', 'HUR'), Money::fromDecimal('250.00')),
        new LineItem('Software licence', Quantity::of(2, 'PCE'), Money::fromDecimal('499.00'), discount: Money::fromDecimal('50.00')),
    ],
)->withInvoiceNumber('INV-2026-0001')->withCounterValue(7);

$credentials = SigningCredentials::fromFiles('/path/certificate.pem', '/path/private-key.pem');
$signed = (new InvoiceSigner($credentials))->sign($invoice);

$signed->xml();            // the exact bytes to submit — hash and signature verify on these
$signed->xmlForDisplay();  // indented copy for logs; never submit this
$signed->qrPayload;        // base64 TLV payload for the QR image
$signed->hash->base64();   // base64(raw) — ds:DigestValue, QR tag 6
$signed->hash->base64OfHex(); // base64(hex) — the PIH for the next invoice
```

Validate before signing:

```php
use Zatca\EInvoice\Validation\{InvoiceValidator, SchemaValidator};

InvoiceValidator::withDefaults()->validate($invoice)->throwIfInvalid();
(new SchemaValidator())->validate($signed->document)->throwIfInvalid();
```

## CLI

```bash
./bin/zatca demo                       # build, validate, sign and inspect a sample invoice
./bin/zatca demo --simplified --out=/tmp/pos.xml
./bin/zatca validate invoice.xml       # XSD check against UBL 2.1
./bin/zatca qr "$(cat qr.txt)"         # decode a ZATCA QR payload
./bin/zatca keygen --out=/tmp/csid     # secp256k1 key + self-signed cert for local testing
```

## How signing works

```
invoice DOM ──► remove UBLExtensions, cac:Signature, AdditionalDocumentReference[QR]
            ──► C14N ──► SHA-256 ──► base64  ──────────────┐  (ds:DigestValue, QR tag 6)
                                                           │
xades:SignedProperties ──► C14N ──► SHA-256 ──► base64(hex)┤  (second ds:Reference)
                                                           │
ds:SignedInfo ──► C14N ──► ECDSA-SHA256 (secp256k1) ───────┴─► ds:SignatureValue, QR tag 7
```

The signature block is attached to the tree *before* its digests are computed, because namespace
declarations inherited from ancestors are part of the canonical bytes.

## Verification and compliance

| Area | Status |
|---|---|
| UBL 2.1 structure | Validated with the vendored OASIS schemas against all five ZATCA SDK samples and against generated documents |
| Invoice hash | Round‑trip tested: re‑parse the emitted XML → hash → compare with `ds:DigestValue` |
| Signature | Verified with `openssl_verify` over the canonicalised `ds:SignedInfo` |
| PIH chain | First invoice uses the published `NWZlY2Vi…` constant; chained invoices carry `base64(hex(previous))`, the same encoding as the seed |
| QR | Phase 1 (tags 1‑5) and Phase 2 (tags 1‑8, optional 9) decoded and asserted in tests |
| Business rules | 24 rules; see [docs/rules.md](docs/rules.md) |
| Certificate digest | Reproduces the `xades:CertDigest` in ZATCA's own sample byte-for-byte |
| Issuer name | Reproduces the `ds:X509IssuerName` in ZATCA's own sample (three `DC=` components in RFC 4514 order) |
| ZATCA API submission | **Not implemented** — this builds documents, it does not clear or report them |

Known limits, stated plainly:

- **Canonicalisation is C14N 1.0, not C14N 1.1.** PHP's DOM exposes only C14N 1.0 and exclusive C14N.
  For documents this library generates the two agree, and every digest is computed with the same
  serialiser that produces the output — but a document that arrives with exotic `xml:base` usage could
  canonicalise differently. This is the one place where a ZATCA acceptance test is still worth running.
- **No `SignaturePolicyIdentifier`.** ZATCA's sample documents omit it; some SDK versions include it.
  It is optional in their schema and not emitted here.
- **No onboarding (CSR → compliance CSID → production CSID).** ZATCA issues those certificates through
  the Fatoora portal with an OTP; this package signs with a certificate you already hold.
- **`SignedProperties` is hashed in place.** Inherited namespace declarations are part of the bytes,
  which is what a DOM-based verifier does; a verifier that hashes a detached copy would disagree.
  A ZATCA sandbox compliance call is the only way to settle which one they recompute.
- **QR tag 9 is not derived.** ZATCA's simplified samples carry the CA's signature over the stamp in
  tag 9; this library accepts it as an optional argument (for a cleared document) rather than
  extracting it from the certificate.
- **Foreign-currency invoices omit the SAR tax total.** ZATCA wants a second `cac:TaxTotal` in SAR
  alongside the document-currency one; that needs an exchange rate this library does not model.
- **PIH chaining after the first invoice is documented, not sandbox-proven.** The published seed is
  base64 of the hex digest (88 chars); this library keeps that encoding for every link and exposes
  `InvoiceHash::base64()` if your validator turns out to want the 44-character `ds:DigestValue` form.
- **Not certified.** Passing `xmllint` and verifying your own signature is necessary, not sufficient.
  Clearance against the ZATCA sandbox is the real gate.

## Design decisions

Short version: invalid states are unrepresentable, money is integer, and the document is a DOM (not a
string) so hashing and signing operate on the same tree. Longer version in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Laravel

```php
// bootstrap/providers.php
Zatca\EInvoice\Laravel\ZatcaServiceProvider::class,
```

```env
ZATCA_CERTIFICATE_PATH=/var/secrets/zatca/certificate.pem
ZATCA_PRIVATE_KEY_PATH=/var/secrets/zatca/private-key.pem
```

`php artisan vendor:publish --tag=zatca-config` publishes the config; `--tag=zatca-schemas` publishes
the XSD set if you validate outside the package.

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 9
./bin/zatca demo   # end-to-end smoke test
```

## Status and roadmap

`0.1.0` is the document layer: build, validate, sign, encode the QR. The next steps, in the order they
unblock a full integration:

- **Onboarding client** — CSR generation with ZATCA's template fields, OTP exchange, compliance and
  production CSID retrieval.
- **Submission** — clearance (`/invoices/clearance/single`) and reporting (`/invoices/reporting/single`)
  calls with the CSID, including the stamped response and QR tag 9.
- **Schematron parity** — widen the ruleset from the 26 rules modelled here to the full BR‑KSA set.
- **QR rendering** — emit an SVG/PNG from the TLV payload without pulling in GD or ImageMagick.

## About

Built by [Abdullah Aldarkhbani](https://abdh01.com) — backend and full‑stack engineer (PHP/Laravel,
C#/.NET) in Jeddah, working on integration‑heavy systems: payment gateways, ERP, APIs and e‑invoicing.
Issues and pull requests are welcome; if you use this in production, a note about which ZATCA sandbox
checks it passed would be genuinely useful to the next person.

## Licence

MIT © [Abdullah Aldarkhbani](https://abdh01.com)
