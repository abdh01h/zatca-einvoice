# Contributing

## Ground rules

- **Money never becomes a float.** Use `Money` and `Quantity`; do not add arithmetic that bypasses
  `Rounding`.
- **The signed bytes are the submittable bytes.** Any change to serialisation must keep
  `SignedDocument::xml()` byte‑identical to what was hashed.
- **A rule needs a test that fails without it.** Add the fixture, assert the rule id, and keep the
  message specific.

## Workflow

```bash
composer install
composer test       # PHPUnit
composer analyse    # PHPStan level 9
./bin/zatca demo    # smoke test
```

Pull requests should keep the suite green, add a changelog line, and explain any change to the
canonicalisation or signature path in `docs/COMPLIANCE.md`.
