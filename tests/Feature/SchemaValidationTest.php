<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Validation\SchemaValidator;

/**
 * The vendored XSDs must accept ZATCA's own sample documents.
 *
 * If this test fails the schema set is broken or was replaced with a different
 * UBL version, which would make every "validates" claim in the README false.
 */
final class SchemaValidationTest extends TestCase
{
    #[DataProvider('samples')]
    public function test_zatca_sample_documents_validate(string $file): void
    {
        $result = (new SchemaValidator())->validateXml((string) file_get_contents($file));

        self::assertTrue($result->isValid(), $file."\n".implode("\n", $result->messages()));
    }

    /** @return iterable<string, array{string}> */
    public static function samples(): iterable
    {
        $base = __DIR__.'/../fixtures/zatca-samples';

        foreach (glob($base.'/*/*.xml') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    public function test_a_deliberately_broken_document_is_rejected(): void
    {
        $xml = (string) file_get_contents(__DIR__.'/../fixtures/zatca-samples/simplified/Simplified_Invoice.xml');
        // well-formed but not schema-valid: IssueDate must be an xsd:date
        $broken = (string) preg_replace('#<cbc:IssueDate>[^<]+</cbc:IssueDate>#', '<cbc:IssueDate>not-a-date</cbc:IssueDate>', $xml, 1);

        $result = (new SchemaValidator())->validateXml($broken);

        self::assertFalse($result->isValid());
        self::assertSame('XSD', $result->errors()[0]->ruleId);
    }

    public function test_the_vendored_schemas_are_the_oasis_ubl_2_1_release(): void
    {
        $schema = (new SchemaValidator())->schemaPath();

        self::assertFileExists($schema);
        self::assertSame(
            '40fae8cb436f3a9506d7acce65ba162caef3b0bed4d5cbc0992b2153ded4edf4',
            hash_file('sha256', $schema),
        );
    }
}
