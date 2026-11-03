#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CI gate: prove the vendored schemas and the generated documents agree.
 *
 * Validates every vendored ZATCA sample, then builds and signs a fresh invoice
 * and checks both the XSD and the totals reconciliation. Exits non-zero on any
 * failure so the workflow fails loudly.
 */

use Zatca\EInvoice\Crypto\DevCertificate;
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;
use Zatca\EInvoice\Validation\DocumentValidator;
use Zatca\EInvoice\Validation\SchemaValidator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$schema = new SchemaValidator();
$failures = 0;

foreach (glob(dirname(__DIR__).'/fixtures/zatca-samples/*/*.xml') ?: [] as $file) {
    $xml = (string) file_get_contents($file);
    $result = $schema->validateXml($xml);

    if (! $result->isValid()) {
        $failures++;
        fwrite(STDERR, basename($file).' failed XSD validation:'."\n  ".implode("\n  ", $result->messages())."\n");
        continue;
    }

    echo basename($file).": XSD ok\n";
}

$signed = (new InvoiceSigner(DevCertificate::credentials()))->sign(InvoiceFactory::standard());

$result = $schema->validate($signed->document)->merge((new DocumentValidator())->validate($signed->document));

if (! $result->isValid()) {
    $failures++;
    fwrite(STDERR, "Generated invoice failed:\n  ".implode("\n  ", $result->messages())."\n");
} else {
    echo "Generated invoice: XSD ok, totals reconcile\n";
}

exit($failures === 0 ? 0 : 1);
