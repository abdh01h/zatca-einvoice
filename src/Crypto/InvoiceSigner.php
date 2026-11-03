<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Exception\SigningException;
use Zatca\EInvoice\Qr\QrPayload;
use Zatca\EInvoice\Xml\InvoiceDocumentBuilder;
use Zatca\EInvoice\Xml\Namespaces;

/**
 * Applies ZATCA's XAdES B-B "enveloped" signature to an invoice.
 *
 * The order of operations is load-bearing and mirrors the spec:
 *
 *   1. hash the document with UBLExtensions / QR reference / cac:Signature removed
 *   2. build the ds:Signature skeleton and attach it to the tree (namespace
 *      inheritance changes the canonical bytes, so digests are computed on the
 *      attached nodes, never on a detached copy)
 *   3. fill the two Reference digests
 *   4. canonicalise ds:SignedInfo, sign it with ECDSA-SHA256 over secp256k1
 *   5. add the QR AdditionalDocumentReference and the cac:Signature reference
 */
final class InvoiceSigner
{
    public function __construct(
        private readonly SigningCredentials $credentials,
        private readonly InvoiceDocumentBuilder $builder = new InvoiceDocumentBuilder(),
    ) {
    }

    public function sign(Invoice $invoice, ?\DOMDocument $document = null): SignedDocument
    {
        $document ??= $this->builder->build($invoice);

        $hash = InvoiceHasher::hash($document);
        $signature = $this->createSignature($document);
        $extensions = $this->wrap($document, $signature);

        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            throw new SigningException('The document has no root element.');
        }

        $root->insertBefore($extensions, $root->firstChild);

        $this->fillDigests($document, $hash);

        $signedInfo = $this->first($document, '//ds:SignedInfo');

        if (! $signedInfo instanceof \DOMElement) {
            throw new SigningException('ds:SignedInfo is missing from the signature.');
        }

        $signatureValue = $this->credentials->sign(Canonicalizer::canonicalize($signedInfo));

        $valueNode = $this->first($document, '//ds:SignatureValue');

        if (! $valueNode instanceof \DOMElement) {
            throw new SigningException('ds:SignatureValue is missing from the signature.');
        }

        $valueNode->nodeValue = $signatureValue;

        $qr = QrPayload::phaseTwo(
            $invoice,
            $hash,
            $signatureValue,
            base64_encode($this->credentials->publicKeyDer()),
        );

        $this->insertQrReference($document, $qr);
        $this->insertSignatureReference($document);

        $xml = $document->saveXML();

        if ($xml === false) {
            throw new SigningException('The signed document could not be serialised.');
        }

        return new SignedDocument(
            document: $document,
            invoice: $invoice->withQr($qr),
            hash: $hash,
            signatureValue: $signatureValue,
            qrPayload: $qr,
            xml: $xml,
        );
    }

    private function createSignature(\DOMDocument $document): \DOMElement
    {
        $ds = function (string $name, ?string $text = null, array $attributes = []) use ($document): \DOMElement {
            $element = $document->createElementNS(Namespaces::DS, 'ds:'.$name);

            if ($text !== null) {
                $element->appendChild($document->createTextNode($text));
            }

            foreach ($attributes as $key => $value) {
                $element->setAttribute($key, $value);
            }

            return $element;
        };

        $signature = $ds('Signature', null, ['Id' => 'signature']);
        $signature->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', Namespaces::DS);

        $signedInfo = $ds('SignedInfo');
        $signedInfo->appendChild($ds('CanonicalizationMethod', null, ['Algorithm' => Namespaces::C14N11]));
        $signedInfo->appendChild($ds('SignatureMethod', null, ['Algorithm' => Namespaces::ECDSA_SHA256]));

        $invoiceReference = $ds('Reference', null, ['Id' => 'invoiceSignedData', 'URI' => '']);
        $transforms = $ds('Transforms');

        foreach ([
            'not(//ancestor-or-self::ext:UBLExtensions)',
            'not(//ancestor-or-self::cac:Signature)',
            "not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])",
        ] as $expression) {
            $transform = $ds('Transform', null, ['Algorithm' => Namespaces::XPATH_TRANSFORM]);
            $transform->appendChild($ds('XPath', $expression));
            $transforms->appendChild($transform);
        }

        $transforms->appendChild($ds('Transform', null, ['Algorithm' => Namespaces::C14N11]));
        $invoiceReference->appendChild($transforms);
        $invoiceReference->appendChild($ds('DigestMethod', null, ['Algorithm' => Namespaces::SHA256]));
        $invoiceReference->appendChild($ds('DigestValue', ''));
        $signedInfo->appendChild($invoiceReference);

        $propertiesReference = $ds('Reference', null, [
            'Type' => Namespaces::SIGNATURE_PROPERTIES_TYPE,
            'URI' => '#xadesSignedProperties',
        ]);
        $propertiesReference->appendChild($ds('DigestMethod', null, ['Algorithm' => Namespaces::SHA256]));
        $propertiesReference->appendChild($ds('DigestValue', ''));
        $signedInfo->appendChild($propertiesReference);

        $signature->appendChild($signedInfo);
        $signature->appendChild($ds('SignatureValue', ''));

        $keyInfo = $ds('KeyInfo');
        $x509Data = $ds('X509Data');
        $x509Data->appendChild($ds('X509Certificate', base64_encode($this->credentials->certificateDer())));
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        $object = $ds('Object');
        $qualifyingProperties = $document->createElementNS(Namespaces::XADES, 'xades:QualifyingProperties');
        $qualifyingProperties->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xades', Namespaces::XADES);
        $qualifyingProperties->setAttribute('Target', 'signature');

        $signedProperties = $document->createElementNS(Namespaces::XADES, 'xades:SignedProperties');
        $signedProperties->setAttribute('Id', 'xadesSignedProperties');

        $signatureProperties = $document->createElementNS(Namespaces::XADES, 'xades:SignedSignatureProperties');
        $signatureProperties->appendChild($document->createElementNS(
            Namespaces::XADES,
            'xades:SigningTime',
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ));

        $signingCertificate = $document->createElementNS(Namespaces::XADES, 'xades:SigningCertificate');
        $cert = $document->createElementNS(Namespaces::XADES, 'xades:Cert');
        $certDigest = $document->createElementNS(Namespaces::XADES, 'xades:CertDigest');
        $certDigest->appendChild($ds('DigestMethod', null, ['Algorithm' => Namespaces::SHA256]));
        $certDigest->appendChild($ds('DigestValue', $this->credentials->certificateDigest()));
        $cert->appendChild($certDigest);

        $issuerSerial = $document->createElementNS(Namespaces::XADES, 'xades:IssuerSerial');
        $issuerSerial->appendChild($ds('X509IssuerName', $this->credentials->issuerName()));
        $issuerSerial->appendChild($ds('X509SerialNumber', $this->credentials->serialNumber()));
        $cert->appendChild($issuerSerial);
        $signingCertificate->appendChild($cert);
        $signatureProperties->appendChild($signingCertificate);

        $signedProperties->appendChild($signatureProperties);
        $qualifyingProperties->appendChild($signedProperties);
        $object->appendChild($qualifyingProperties);
        $signature->appendChild($object);

        return $signature;
    }

    private function wrap(\DOMDocument $document, \DOMElement $signature): \DOMElement
    {
        $extensions = $document->createElementNS(Namespaces::EXT, 'ext:UBLExtensions');
        $extension = $document->createElementNS(Namespaces::EXT, 'ext:UBLExtension');
        $uri = $document->createElementNS(Namespaces::EXT, 'ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $content = $document->createElementNS(Namespaces::EXT, 'ext:ExtensionContent');

        $documentSignatures = $document->createElementNS(Namespaces::SIG, 'sig:UBLDocumentSignatures');
        $documentSignatures->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sig', Namespaces::SIG);
        $documentSignatures->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', Namespaces::SAC);
        $documentSignatures->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sbc', Namespaces::SBC);

        $information = $document->createElementNS(Namespaces::SAC, 'sac:SignatureInformation');
        $information->appendChild($document->createElementNS(Namespaces::CBC, 'cbc:ID', 'urn:oasis:names:specification:ubl:signature:1'));
        $information->appendChild($document->createElementNS(
            Namespaces::SBC,
            'sbc:ReferencedSignatureID',
            $this->signatureReferenceId($document),
        ));
        $information->appendChild($signature);

        $documentSignatures->appendChild($information);
        $content->appendChild($documentSignatures);
        $extension->appendChild($uri);
        $extension->appendChild($content);
        $extensions->appendChild($extension);

        return $extensions;
    }

    private function signatureReferenceId(\DOMDocument $document): string
    {
        $element = $document->documentElement;

        if (! $element instanceof \DOMElement) {
            throw new SigningException('The document has no root element.');
        }

        $root = $element->localName;

        return Namespaces::UBL_SIGNATURE_URI_PREFIX.$root;
    }

    private function fillDigests(\DOMDocument $document, InvoiceHash $hash): void
    {
        $references = $this->elements($document, '//ds:SignedInfo/ds:Reference');

        $invoiceDigest = null;
        $propertiesDigest = null;

        foreach ($references as $reference) {
            $value = $this->first($document, './/ds:DigestValue', $reference);

            if (! $value instanceof \DOMElement) {
                continue;
            }

            if ($reference->getAttribute('Id') === 'invoiceSignedData') {
                $invoiceDigest = $value;
            } else {
                $propertiesDigest = $value;
            }
        }

        if (! $invoiceDigest instanceof \DOMElement || ! $propertiesDigest instanceof \DOMElement) {
            throw new SigningException('Both signature references must be present.');
        }

        $invoiceDigest->nodeValue = $hash->base64();

        $signedProperties = $this->first($document, '//xades:SignedProperties');

        if (! $signedProperties instanceof \DOMElement) {
            throw new SigningException('xades:SignedProperties is missing.');
        }

        $propertiesDigest->nodeValue = base64_encode(
            hash('sha256', Canonicalizer::canonicalize($signedProperties)),
        );
    }

    private function insertQrReference(\DOMDocument $document, string $qr): void
    {
        $last = null;

        foreach ($this->elements($document, '//cac:AdditionalDocumentReference') as $reference) {
            $last = $reference;
        }

        if (! $last instanceof \DOMElement || ! $last->parentNode instanceof \DOMNode) {
            throw new SigningException('The invoice has no AdditionalDocumentReference to anchor the QR code to.');
        }

        $qrReference = $document->createElementNS(Namespaces::CAC, 'cac:AdditionalDocumentReference');
        $qrReference->appendChild($document->createElementNS(Namespaces::CBC, 'cbc:ID', 'QR'));
        $attachment = $document->createElementNS(Namespaces::CAC, 'cac:Attachment');
        $binary = $document->createElementNS(Namespaces::CBC, 'cbc:EmbeddedDocumentBinaryObject', $qr);
        $binary->setAttribute('mimeCode', 'text/plain');
        $attachment->appendChild($binary);
        $qrReference->appendChild($attachment);

        $last->parentNode->insertBefore($qrReference, $last->nextSibling);
    }

    private function insertSignatureReference(\DOMDocument $document): void
    {
        $supplier = $this->first($document, '//cac:AccountingSupplierParty');

        if (! $supplier instanceof \DOMElement || ! $supplier->parentNode instanceof \DOMNode) {
            throw new SigningException('The invoice has no AccountingSupplierParty element.');
        }

        $signature = $document->createElementNS(Namespaces::CAC, 'cac:Signature');
        $signature->appendChild($document->createElementNS(Namespaces::CBC, 'cbc:ID', $this->signatureReferenceId($document)));
        $signature->appendChild($document->createElementNS(
            Namespaces::CBC,
            'cbc:SignatureMethod',
            'urn:oasis:names:specification:ubl:dsig:enveloped:xades',
        ));

        $supplier->parentNode->insertBefore($signature, $supplier);
    }

    private function first(\DOMDocument $document, string $expression, ?\DOMNode $context = null): ?\DOMElement
    {
        $result = $this->xpath($document)->query($expression, $context);

        if ($result === false) {
            throw new SigningException(sprintf('Invalid XPath: %s', $expression));
        }

        $node = $result->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    /** @return list<\DOMElement> */
    private function elements(\DOMDocument $document, string $expression): array
    {
        $result = $this->xpath($document)->query($expression);

        if ($result === false) {
            throw new SigningException(sprintf('Invalid XPath: %s', $expression));
        }

        $elements = [];

        foreach ($result as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function xpath(\DOMDocument $document): \DOMXPath
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cac', Namespaces::CAC);
        $xpath->registerNamespace('cbc', Namespaces::CBC);
        $xpath->registerNamespace('ext', Namespaces::EXT);
        $xpath->registerNamespace('sig', Namespaces::SIG);
        $xpath->registerNamespace('sac', Namespaces::SAC);
        $xpath->registerNamespace('sbc', Namespaces::SBC);
        $xpath->registerNamespace('ds', Namespaces::DS);
        $xpath->registerNamespace('xades', Namespaces::XADES);

        return $xpath;
    }
}
