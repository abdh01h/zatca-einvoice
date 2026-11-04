# AGENTS.md

Working notes for AI agents editing this repository. The detailed version lives in
`.claude/skills/zatca-einvoice/SKILL.md` — read it before changing `src/`.

**What this is:** a PHP 8.2+ library that builds, validates and signs Saudi ZATCA (Fatoora) Phase-2
e-invoices (UBL 2.1 XML, QR payloads, XAdES B-B ECDSA/secp256k1). No runtime dependencies beyond
`ext-dom`, `ext-mbstring`, `ext-openssl`.

```bash
composer install
composer test                       # PHPUnit
composer analyse                    # PHPStan level 9, must stay clean
php tests/tools/validate-xsd.php    # XSD gate over ZATCA's samples + a generated invoice
./bin/zatca demo                    # end-to-end smoke test
```

## Five things that will bite you

1. **Bytes matter.** `SignedDocument::xml()` returns the exact bytes that were hashed and signed.
   Indenting them changes the canonical form and breaks the hash — never "just pretty-print" it.
2. **Two hash encodings.** `ds:DigestValue`/QR tag 6 = base64(raw digest); PIH = base64(hex digest).
   Use `InvoiceHash::base64()` / `base64OfHex()`; do not convert inline.
3. **`xades:CertDigest` hashes the certificate's base64 text, not its DER**, then base64s the hex digest.
   `tests/Feature/ZatcaInteropTest.php` pins this against ZATCA's own sample bytes.
4. **Issuer DN: flatten the parsed RDN list, then reverse it** — otherwise repeated `DC=` components come
   out backwards.
5. **QR tags 6-7 are ASCII base64; tags 8-9 are raw DER.** Tag 9 only exists after clearance.

## Do not

- Introduce floats for money, or round anywhere except `Rounding::halfUp`.
- Add validator rules for states the constructors already make impossible.
- Invent `BR-KSA-*` ids — use the published list in `docs/rules.md` or a local `KSA-*` id.
- Claim compliance, gateway acceptance or certification that a test does not prove.
- Add Composer runtime dependencies; the zero-dependency property is deliberate.

`docs/COMPLIANCE.md` separates what is verified from what still needs a ZATCA sandbox CSID. Keep it honest.
