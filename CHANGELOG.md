# Changelog

All notable changes are documented here. The format follows Keep a Changelog and the project uses
semantic versioning.

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
- Business‑rule validator with 26 rules keyed to ZATCA BR‑KSA/BR‑CO/BR‑S ids, plus XSD validation against vendored OASIS UBL 2.1 schemas.
- CLI: `demo`, `validate`, `qr`, `keygen`.
- Optional Laravel service provider, publishable config and schemas.
- 69 tests covering the domain model, XML shape, hashing round‑trips, signature verification, tamper
  detection, QR payload encodings, certificate bundles and all five ZATCA SDK samples.
