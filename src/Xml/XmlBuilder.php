<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Xml;

/**
 * A deliberately small DOM helper.
 *
 * DOM (rather than string concatenation) is used because the same tree has to
 * be canonicalised for hashing and signing later on; building the text by hand
 * would let the digest and the document drift apart.
 */
final class XmlBuilder
{
    private const XMLNS = 'http://www.w3.org/2000/xmlns/';

    public readonly \DOMDocument $document;
    public readonly \DOMElement $root;

    /**
     * @param array<string, string> $namespaces prefix => URI, declared on the root element
     */
    public function __construct(string $rootName, string $rootNamespace, array $namespaces = [])
    {
        $this->document = new \DOMDocument('1.0', 'UTF-8');
        $this->root = $this->document->createElementNS($rootNamespace, $rootName);

        $this->root->setAttributeNS(self::XMLNS, 'xmlns', $rootNamespace);

        foreach ($namespaces as $prefix => $uri) {
            if ($this->root->hasAttribute('xmlns:'.$prefix)) {
                continue;
            }

            $this->root->setAttributeNS(self::XMLNS, 'xmlns:'.$prefix, $uri);
        }

        $this->document->appendChild($this->root);
    }

    /**
     * @param array<string, string> $attributes
     */
    public function add(\DOMNode $parent, string $qualifiedName, ?string $text = null, array $attributes = []): \DOMElement
    {
        $element = $this->document->createElementNS(Prefixes::uriFor($qualifiedName), $qualifiedName);

        if ($text !== null) {
            $element->appendChild($this->document->createTextNode($text));
        }

        foreach ($attributes as $name => $value) {
            $element->setAttribute($name, $value);
        }

        $parent->appendChild($element);

        return $element;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function money(\DOMNode $parent, string $qualifiedName, \Zatca\EInvoice\Domain\Money $amount, array $attributes = []): \DOMElement
    {
        return $this->add(
            $parent,
            $qualifiedName,
            $amount->toDecimal(),
            ['currencyID' => $amount->currency->value] + $attributes,
        );
    }

    public function toXml(): string
    {
        $xml = $this->document->saveXML();

        return $xml === false ? '' : $xml;
    }
}
