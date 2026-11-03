<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

use Zatca\EInvoice\Xml\RootElement;
use Zatca\EInvoice\Exception\ZatcaException;

/**
 * XSD validation against the OASIS UBL 2.1 schemas vendored in resources/xsd.
 *
 * XSD validation is necessary but not sufficient: it proves the document is
 * well-formed UBL, not that ZATCA's business rules (which live in Schematron)
 * are satisfied. {@see InvoiceValidator} covers the rules this library models.
 */
final class SchemaValidator
{
    private readonly string $schemaDirectory;

    public function __construct(?string $schemaDirectory = null)
    {
        $this->schemaDirectory = $schemaDirectory ?? \dirname(__DIR__, 2).'/resources/xsd';
    }

    public function validate(\DOMDocument $document, RootElement $root = RootElement::Invoice): ValidationResult
    {
        $schema = $this->schemaPath($root);

        if (! is_file($schema)) {
            throw new ZatcaException(sprintf('Schema not found: %s', $schema));
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $valid = $document->schemaValidate($schema);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $violations = [];

        foreach ($errors as $error) {
            $violations[] = new Violation(
                ruleId: 'XSD',
                message: trim($error->message),
                severity: Severity::Error,
                path: $error->line > 0 ? 'line '.$error->line : null,
            );
        }

        if (! $valid && $violations === []) {
            $violations[] = new Violation('XSD', 'The document does not validate against the UBL 2.1 schema.');
        }

        return new ValidationResult($violations);
    }

    public function validateXml(string $xml, RootElement $root = RootElement::Invoice): ValidationResult
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $this->validate($document, $root);
    }

    public function schemaPath(RootElement $root = RootElement::Invoice): string
    {
        return $this->schemaDirectory.'/maindoc/'.$root->schemaFile();
    }
}
