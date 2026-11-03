<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Zatca\EInvoice\Crypto\Canonicalizer;
use Zatca\EInvoice\Crypto\InvoiceHasher;
use Zatca\EInvoice\Crypto\InvoiceSigner;
use Zatca\EInvoice\Crypto\InvoiceHash;
use Zatca\EInvoice\Qr\QrPayload;
use Zatca\EInvoice\Tests\Support\InvoiceFactory;
use Zatca\EInvoice\Tests\Support\TestCredentials;
use Zatca\EInvoice\Validation\SchemaValidator;

final class SigningTest extends TestCase
{
    public function test_it_signs_a_document_that_validates_and_verifies(): void
    {
        $credentials = TestCredentials::make();
        $signed = (new InvoiceSigner($credentials))->sign(InvoiceFactory::standard());

        self::assertTrue((new SchemaValidator())->validate($signed->document)->isValid());

        $xpath = $this->xpath($signed->document);
        $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        self::assertInstanceOf(\DOMNode::class, $signedInfo);

        self::assertTrue(
            $credentials->verify(Canonicalizer::canonicalize($signedInfo), $signed->signatureValue),
            'The ECDSA signature must verify against the certificate.',
        );
    }

    public function test_the_hash_survives_a_serialise_and_reparse_round_trip(): void
    {
        $signed = (new InvoiceSigner(TestCredentials::make()))->sign(InvoiceFactory::standard());

        $reparsed = new \DOMDocument();
        self::assertTrue($reparsed->loadXML($signed->xml()));

        $hash = InvoiceHasher::hash($reparsed);
        $xpath = $this->xpath($reparsed);

        self::assertSame(
            $xpath->query('//ds:Reference[@Id="invoiceSignedData"]/ds:DigestValue')->item(0)?->nodeValue,
            $hash->base64(),
            'ZATCA recomputes this digest from the bytes we submit.',
        );
    }

    public function test_pretty_printing_is_not_the_signed_document(): void
    {
        $signed = (new InvoiceSigner(TestCredentials::make()))->sign(InvoiceFactory::standard());

        $pretty = new \DOMDocument();
        self::assertTrue($pretty->loadXML($signed->xmlForDisplay()));

        self::assertGreaterThan(50, substr_count($signed->xmlForDisplay(), "\n"), 'The display copy is indented.');
        self::assertLessThan(3, substr_count($signed->xml(), "\n"), 'The signed bytes carry no formatting whitespace.');

        $signedHash = InvoiceHasher::hash($signed->document);
        $prettyHash = InvoiceHasher::hash($pretty);

        self::assertFalse(
            $signedHash->equals($prettyHash),
            'Whitespace between elements is part of the canonical form, so indentation breaks the hash.',
        );
    }

    public function test_tampering_with_an_amount_breaks_the_hash(): void
    {
        $signed = (new InvoiceSigner(TestCredentials::make()))->sign(InvoiceFactory::standard());

        $tampered = new \DOMDocument();
        self::assertTrue($tampered->loadXML($signed->xml()));

        $xpath = $this->xpath($tampered);
        $payable = $xpath->query('//cac:LegalMonetaryTotal/cbc:PayableAmount')->item(0);
        self::assertInstanceOf(\DOMElement::class, $payable);
        $payable->nodeValue = '1.00';

        $original = $xpath->query('//ds:Reference[@Id="invoiceSignedData"]/ds:DigestValue')->item(0)?->nodeValue;

        self::assertSame($original, InvoiceHasher::hash($signed->document)->base64());
        self::assertNotSame($original, InvoiceHasher::hash($tampered)->base64());
    }

    public function test_the_qr_carries_the_phase_two_tags(): void
    {
        $invoice = InvoiceFactory::simplified();
        $credentials = TestCredentials::make();
        $signed = (new InvoiceSigner($credentials))->sign($invoice);
        $fields = $signed->qrFields();

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_keys($fields));
        self::assertSame($invoice->seller->registrationName, $fields[1]);
        self::assertSame($invoice->seller->vatNumber?->value, $fields[2]);
        self::assertSame('2026-11-03T17:41:08Z', $fields[3]);
        self::assertSame($invoice->totals()->taxInclusiveAmount->toDecimal(), $fields[4]);
        self::assertSame($invoice->totals()->taxAmount->toDecimal(), $fields[5]);
        self::assertSame($signed->hash->base64(), $fields[6]);
        self::assertSame($signed->signatureValue, $fields[7]);
        self::assertSame($credentials->publicKeyDer(), $fields[8], 'Tag 8 is raw DER, not base64.');
        self::assertStringStartsWith("\x30", $fields[8], 'A DER SEQUENCE starts with 0x30.');
        self::assertSame(88, strlen($fields[8]));
    }

    public function test_phase_one_qr_contains_only_the_five_generation_tags(): void
    {
        $fields = \Zatca\EInvoice\Qr\Tlv::decode(QrPayload::phaseOne(InvoiceFactory::simplified()));

        self::assertSame([1, 2, 3, 4, 5], array_keys($fields));
    }

    public function test_the_previous_invoice_hash_chains_into_the_pih_element(): void
    {
        $credentials = TestCredentials::make();
        $signer = new InvoiceSigner($credentials);

        $first = $signer->sign(InvoiceFactory::standard());
        $second = $signer->sign(InvoiceFactory::standard()->withPreviousInvoiceHash($first->hash->base64OfHex()));

        $xpath = $this->xpath($second->document);
        $pih = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject")->item(0)?->nodeValue;

        self::assertSame($first->hash->base64OfHex(), $pih);
        self::assertSame(88, strlen((string) $pih), 'PIH is base64 of the 64-character hex digest.');
    }

    public function test_the_first_invoice_uses_the_published_initial_hash(): void
    {
        $signed = (new InvoiceSigner(TestCredentials::make()))->sign(InvoiceFactory::standard());
        $xpath = $this->xpath($signed->document);
        $pih = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject")->item(0)?->nodeValue;

        self::assertSame('NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==', $pih);
        self::assertSame($pih, InvoiceHash::initialPreviousHash());
    }

    public function test_the_extension_block_is_wrapped_and_referenced(): void
    {
        $signed = (new InvoiceSigner(TestCredentials::make()))->sign(InvoiceFactory::standard());
        $xpath = $this->xpath($signed->document);

        self::assertSame('UBLExtensions', $signed->document->documentElement?->firstChild?->localName);
        self::assertSame(1, $xpath->query('//ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent')->length);
        self::assertSame(1, $xpath->query('//ds:Signature[@Id="signature"]')->length);
        self::assertSame(1, $xpath->query('//cac:Signature')->length);
        self::assertSame(
            'urn:oasis:names:specification:ubl:signature:Invoice',
            $xpath->query('//cac:Signature/cbc:ID')->item(0)?->nodeValue,
        );
        self::assertSame(
            'urn:oasis:names:specification:ubl:signature:Invoice',
            $xpath->query('//sbc:ReferencedSignatureID')->item(0)?->nodeValue,
        );
    }

    private function xpath(\DOMDocument $document): \DOMXPath
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('sbc', 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2');

        return $xpath;
    }
}
