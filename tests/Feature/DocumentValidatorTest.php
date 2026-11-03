<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Crypto\DevCertificate;
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;
use Zatca\EInvoice\Validation\DocumentValidator;
use Zatca\EInvoice\Xml\InvoiceDocumentBuilder;

final class DocumentValidatorTest extends TestCase
{
    public function test_a_generated_document_reconciles(): void
    {
        $signed = (new InvoiceSigner(DevCertificate::credentials()))->sign(InvoiceFactory::standard());
        $result = (new DocumentValidator())->validate($signed->document);

        self::assertTrue($result->isValid(), implode("\n", $result->messages()));
    }

    public function test_a_tampered_payable_amount_is_caught(): void
    {
        $document = (new InvoiceDocumentBuilder())->build(InvoiceFactory::standard());
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $payable = $xpath->query('//cac:LegalMonetaryTotal/cbc:PayableAmount')->item(0);
        self::assertInstanceOf(\DOMElement::class, $payable);
        $payable->nodeValue = '1.00';

        $ids = array_map(static fn ($v) => $v->ruleId, (new DocumentValidator())->validate($document)->errors());

        self::assertContains('BR-CO-16', $ids);
    }

    public function test_a_tampered_line_amount_breaks_the_line_sum(): void
    {
        $document = (new InvoiceDocumentBuilder())->build(InvoiceFactory::standard());
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $line = $xpath->query('//cac:InvoiceLine/cbc:LineExtensionAmount')->item(0);
        self::assertInstanceOf(\DOMElement::class, $line);
        $line->nodeValue = '1.00';

        $ids = array_map(static fn ($v) => $v->ruleId, (new DocumentValidator())->validate($document)->errors());

        self::assertContains('BR-CO-10', $ids);
    }

    #[DataProvider('samples')]
    public function test_zatca_samples_reconcile(string $file): void
    {
        $document = new \DOMDocument();
        $document->load($file);

        $result = (new DocumentValidator())->validate($document);

        self::assertTrue($result->isValid(), basename($file)."\n".implode("\n", $result->messages()));
    }

    /** @return iterable<string, array{string}> */
    public static function samples(): iterable
    {
        foreach (glob(__DIR__.'/../fixtures/zatca-samples/*/*.xml') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }
}
