# Architecture

Four layers, each with one job. The dependency direction is one‑way: `Domain` knows nothing about XML,
`Xml` knows nothing about crypto, and `Crypto` only knows how to read and sign a DOM.

```
Domain      invoice aggregate, money, tax categories, totals calculator
  │
  ├── Xml   UBL 2.1 document builder (DOM)
  │
  ├── Crypto  hashing, canonicalisation, XAdES signature, signing credentials
  │
  ├── Qr     TLV payloads (phase 1 and phase 2)
  │
  └── Validation  business rules + XSD schema check
```

## Decisions worth arguing about

**Money is an integer.** `Money` holds minor units (halalas) and a currency; `Quantity` holds
thousandths of a unit. Multiplication and percentages route through `Rounding::halfUp()`, so there is
exactly one rounding point per line and totals are reproducible. A float never touches an amount,
which is why `Money::fromDecimal()` rejects `10.005` instead of silently rounding it.

**Rounding happens per line, then sums.** ZATCA accepts line‑level rounding, and it keeps each printed
line's VAT equal to the VAT in the total. The alternative — rounding the document total — makes the
line figures disagree with `TaxAmount`, which fails the business rules.

**Invalid states are unrepresentable where possible.** A `Invoice` cannot exist without a seller VAT
number, a UUID, at least one line, or mixed currencies. A credit note cannot exist without a billing
reference. A line discount cannot exceed its line extension. That is why the ruleset does not repeat
those checks: they are enforced by constructor type signatures, not by validators.

**The document is a DOM, not a string.** Hashing, signing and serialisation all operate on the same
tree, so there is no "the string I hashed" versus "the string I sent" gap. Pretty printing is a
separate, clearly labelled display path.

**Signature digests are computed on attached nodes.** `xades:SignedProperties` inherits namespace
declarations from its ancestors; canonicalising a detached copy produces different bytes. The signer
therefore attaches the skeleton, then fills the digests, then signs `ds:SignedInfo`.

**The signed bytes are the only bytes.** `SignedDocument::xml()` returns what was hashed and signed;
`xmlForDisplay()` re‑indents a copy. Whitespace between elements is part of C14N, so the display copy
would not clear.

## Data flow

```php
$invoice = Invoice::draft(...);                  // Domain, immutable
$totals  = $invoice->totals();                   // InvoiceCalculator
$doc     = (new InvoiceDocumentBuilder())->build($invoice);  // unsigned UBL
$hash    = InvoiceHasher::hash($doc);            // strip + C14N + SHA-256
$signed  = (new InvoiceSigner($creds))->sign($invoice, $doc); // extension + QR + reference
```

`InvoiceSigner` accepts a prebuilt document so a caller can inspect or modify the XML before signing,
and the signer will hash exactly what it is given.

## Extension points

- `Rule` — implement `id()`, `description()`, `severity()`, `check()`. Pass a custom list to
  `new InvoiceValidator([...])` to replace or extend the default ruleset.
- `RootElement` — `Invoice` (default, matches ZATCA samples), `CreditNote`, `DebitNote`.
- `SchemaValidator` — pass a custom schema directory when an authority ships updated schemas.
