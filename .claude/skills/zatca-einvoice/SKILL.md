---
name: zatca-einvoice
description: Work on the ZATCA Phase-2 e-invoicing library: build, sign, validate.
---

# ZATCA e-invoicing library — agent working notes

PHP library that produces, validates and signs Saudi ZATCA (Fatoora) Phase-2 e-invoices.
Read this before touching `src/`; almost every bug in this domain is an encoding detail that
tests cannot guess for you.

## 60-second orientation

```bash
composer install
composer test                       # PHPUnit, 97 tests
composer analyse                    # PHPStan level 9, must stay clean
php tests/tools/validate-xsd.php    # XSD gate: 5 ZATCA samples + a generated invoice
./bin/zatca demo                    # end-to-end: build -> sign -> QR -> validate
./bin/zatca demo --simplified --out=/tmp/pos.xml
./bin/zatca validate some-invoice.xml
./bin/zatca csr --manufacturer=Acme --model=POS-1 --serial=123 --org=Acme --branch=Riyadh
```

Constraints: PHP **8.2+**, no runtime dependencies beyond `ext-dom`, `ext-mbstring`, `ext-openssl`.
`ext-curl` is used only by the gateway's default transport. Do not add Composer runtime deps
without a strong reason — the zero-dependency property is deliberate.

## Layout

```
src/Domain/      money (integer minor units), quantity, tax categories, Invoice aggregate,
                 InvoiceCalculator (single rounding point), ExchangeRate
src/Xml/         UBL 2.1 builder (DOM, XSD element order), RootElement (Invoice|CreditNote|DebitNote)
src/Crypto/      InvoiceHasher (strip + C14N + SHA-256), InvoiceSigner (XAdES B-B), SigningCredentials,
                 InvoiceHash (the two encodings), Der (hand-rolled ASN.1 for the CSR)
src/Qr/          Tlv codec + QrPayload (phase 1 tags 1-5, phase 2 tags 1-8/9)
src/Validation/  InvoiceValidator + ZatcaRules (BR-KSA/BR-CO/BR-S ids), DocumentValidator, SchemaValidator
src/Gateway/     ZatcaGateway (CSID, clearance, reporting) over an injectable HttpClient
src/Onboarding/  Egs + CsrBuilder (ZATCA CSR profile)
bin/zatca        CLI: demo, validate, qr, keygen, csr
resources/xsd/   vendored OASIS UBL 2.1 schemas (hash pinned in a test)
tests/fixtures/  five real ZATCA SDK sample documents
docs/            ARCHITECTURE, COMPLIANCE (verified vs unverified), rules catalogue
```

## Invariants — breaking any of these produces a document that looks fine and is rejected

1. **The signed bytes are the only submittable bytes.** `SignedDocument::xml()` returns exactly what
   was hashed and signed. `xmlForDisplay()` re-indents a copy; whitespace between elements is part of
   C14N, so submitting the pretty version breaks the hash. Never add a `pretty` flag to the serializer.
2. **Two hash encodings exist.** `ds:DigestValue` and QR tag 6 = base64 of the **raw 32-byte** digest
   (44 chars). PIH (the chain) = base64 of the **lowercase hex** digest (88 chars). First invoice uses
   the published constant `base64(hex(sha256("0")))`. `InvoiceHash` exposes `hex()`, `base64()`,
   `base64OfHex()`; never convert by hand at a call site.
3. **Digests are computed on attached nodes.** `xades:SignedProperties` inherits namespace declarations
   from ancestors; canonicalising a detached copy yields different bytes. Attach the signature skeleton,
   then fill digests.
4. **`xades:CertDigest` hashes the certificate's base64 text, not its DER**, then base64-encodes the
   hex digest. `ZatcaInteropTest` proves this against ZATCA's own sample — if you "fix" it to hash DER,
   that test fails.
5. **Issuer DN: flatten, then reverse.** Reversing only the keys leaves repeated `DC=` components in
   certificate order instead of RFC 4514 order. Same test file pins it to the sample.
6. **QR encodings differ per tag.** Tags 6-7 are the ASCII text of base64 strings; tags **8-9 are raw
   DER bytes**. Tag 9 is ZATCA's stamp: it only exists after clearance (`ClearanceResult::$qrPayload`)
   and is **not** derivable from the CSID certificate — that was checked against the samples.
7. **`cbc:ProfileID` is `reporting:1.0` for both subtypes** (BR-KSA-EN16931-01). `clearance:1.0` is a
   common mistake.
8. **381/383 documents use an `<Invoice>` root** with `cbc:InvoiceTypeCode`, because that is what ZATCA's
   samples ship. Native `<CreditNote>`/`<DebitNote>` roots are opt-in via `RootElement`.
9. **Line discounts change the total.** `LineExtensionAmount` on a line is net of the line allowance and
   the allowance is declared as `cac:AllowanceCharge` on that line. `InvoiceCalculator` sums the nets
   (BR-CO-10). Getting this wrong overcharges silently.
10. **Money is integer-only.** Minor units + basis points, one rounding point per line
    (`Rounding::halfUp`). Never introduce a float, and never round a total after summing.
11. **Invalid states are unrepresentable.** Sellers without VAT, empty invoices, mismatched currencies,
    notes without a billing reference all throw at construction. Do not re-add those as validator rules.

## Common changes

**Add a business rule** — append a `CallableRule` in `src/Validation/ZatcaRules.php`, use the published
BR id when the mapping is 1:1 (otherwise a local `KSA-*` id, and say so in `docs/rules.md`), then add a
test that fails without it. Rules must not recompute from the same model they validate — validate the
serialized document instead (`DocumentValidator` shows how).

**Add a UBL element** — the XSD is a `sequence`: order matters, not just presence. Find the element in
`resources/xsd/maindoc/UBL-Invoice-2.1.xsd`, place it accordingly, then run
`php tests/tools/validate-xsd.php`.

**Touch the signing path** — run the full ladder and both `ZatcaInteropTest` and the round-trip test in
`SigningTest` (re-parse emitted bytes, recompute the digest, `openssl_verify` the signature).

**Add a gateway call** — go through `HttpClient`; never call curl inside a gateway method. Test it with
`tests/Support/StubHttpClient`, asserting the endpoint, headers and body you send.

## Verification ladder

Run all five before claiming done:

```bash
composer test && composer analyse
php tests/tools/validate-xsd.php
./bin/zatca demo && ./bin/zatca validate <the file it wrote>
git clone . /tmp/verify && cd /tmp/verify && composer install && composer test   # committed tree is complete
```

CI runs PHP 8.2/8.3/8.4 plus the XSD job — a change that only passes on 8.4 is not done.

## Known-unverified — do not "fix" these blind

- **`SignedProperties` mode**: hashed in place (inherited namespaces included). ZATCA's samples carry
  placeholder digests here, so neither in-place nor detached reproduces them. Only a sandbox compliance
  call settles it.
- **Gateway JSON field names**: follow the published API and are covered by stub tests, but no call has
  been made to the live portal. Treat parsing as tolerant, not authoritative.
- **PIH encoding after the first invoice**: the seed is documented; the chained form follows the same
  encoding here, unproven against the validator.

`docs/COMPLIANCE.md` is the source of truth for what is proven. Keep it accurate; do not upgrade a claim
without a test or a recorded command behind it.

## Symbols and fixtures

- Allowed invoice type codes: 388 invoice, 381 credit note, 383 debit note, 386 prepayment.
- Type name is seven characters `NNPNESB`: `01` standard / `02` simplified, then third-party, nominal,
  export, summary, self-billed. `InvoiceSubtype::withFlags()` builds it.
- VAT categories `S`/`Z`/`E`/`O`; Z/E/O require a `VATEX-SA-*` reason code and (per BR-KSA-83) reason text.
- `tests/fixtures/zatca-samples/` are ZATCA's own documents; the schema set is byte-identical to OASIS
  UBL 2.1 and its hash is pinned in `SchemaValidationTest`.

## Definition of done

- [ ] `composer test` green, `composer analyse` clean
- [ ] `php tests/tools/validate-xsd.php` green
- [ ] `./bin/zatca demo` produces a file that `./bin/zatca validate` accepts
- [ ] new behaviour has a test that fails without it
- [ ] `docs/COMPLIANCE.md` / `docs/rules.md` updated if a claim or rule changed
- [ ] `CHANGELOG.md` has a line
