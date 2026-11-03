# Changelog

All notable changes are documented here. The format follows Keep a Changelog and the project uses
semantic versioning.

## [0.2.0] — 2026-11-03

### Added

- **Onboarding**: `CsrBuilder` emits ZATCA's CSR profile — secp256k1 key, `TST-`/`PRD-` CN,
  `serialNumber = 1-manufacturer|2-model|3-serial`, and the `ZATCA-Code-Signing` template extension.
  The CSR is DER-encoded in-process because OpenSSL types `serialNumber` as PrintableString and
  rejects the pipe characters the profile requires.
- **Gateway client**: compliance CSID, production CSID, compliance check, clearance and reporting over
  an injectable `HttpClient`, returning the cleared/stamped invoice and its QR payload.
- **Foreign-currency invoices**: `ExchangeRate` value object, `cac:TaxExchangeRate` and the second SAR
  `cac:TaxTotal` required by BR-KSA-EN16931-09.
- `Csid` converts ZATCA's base64 token/secret back into working `SigningCredentials`.
- CLI `csr` command; `Der` minimal ASN.1 encoder.

### Changed

- Gateway parsing is tolerant: unmodelled response fields stay in `ClearanceResult::$payload`.

### Notes

- Gateway request/response shapes follow the published API and are covered by stub-transport tests;
  they have not been exercised against the live developer portal.
- QR tag 9 is produced only by ZATCA; `ClearanceResult::$qrPayload` carries it after clearance.

## [0.1.0] — 2026-11-03

### Added

- UBL 2.1 invoice generation for standard and simplified documents, credit and debit notes, with
  `RootElement` selection between `Invoice` (ZATCA sample behaviour) and native note roots.
- Exact integer money and quantity arithmetic with a single, documented rounding point.
- Phase‑1 (tags 1‑5) and Phase‑2 (tags 1‑8, optional 9) QR payloads.
- Invoice hashing with ZATCA's three XPath exclusions, and both hash encodings (`base64(raw)` for
  `ds:DigestValue` and QR tag 6, `base64(hex)` for the PIH chain).
- XAdES B‑B enveloped signature over ECDSA‑SHA256/secp256k1, including certificate digest, issuer
  serial, and the UBLExtensions/sac:SignatureInformation wrapper.
- Business‑rule validator with 24 rules keyed to ZATCA BR‑KSA/BR‑CO/BR‑S ids, plus XSD validation against vendored OASIS UBL 2.1 schemas.
- `DocumentValidator` reconciling BR‑CO‑10/13/15/16 inside any UBL invoice, including foreign documents.
- CLI: `demo`, `validate`, `qr`, `keygen`.
- Optional Laravel service provider, publishable config and schemas.
- 83 tests covering the domain model, XML shape, hashing round‑trips, signature verification, tamper
  detection, QR payload encodings, certificate bundles, line-discount arithmetic, byte-level interop
  with ZATCA's own `CertDigest`/`X509IssuerName`, and all five ZATCA SDK samples.
