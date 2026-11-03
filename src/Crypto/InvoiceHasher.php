<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Exception\SigningException;
use Zatca\EInvoice\Xml\Namespaces;

/**
 * Reproduces ZATCA's invoice digest.
 *
 * The digest is taken over the document with three subtrees removed -- exactly
 * the three XPath transforms listed in ds:SignedInfo -- and then canonicalised:
 *
 *   1. ext:UBLExtensions          (the signature would otherwise hash itself)
 *   2. cac:Signature              (the reference to that signature)
 *   3. cac:AdditionalDocumentReference whose cbc:ID is "QR"
 */
final class InvoiceHasher
{
    public static function hash(\DOMDocument $document): InvoiceHash
    {
        $stripped = self::stripForDigest($document);
        $root = $stripped->documentElement;

        if (! $root instanceof \DOMElement) {
            throw new SigningException('The document has no root element to hash.');
        }

        $canonical = Canonicalizer::canonicalize($root);

        return InvoiceHash::fromRaw(Canonicalizer::sha256($canonical));
    }

    /** Returns a copy with the three excluded subtrees removed. */
    public static function stripForDigest(\DOMDocument $document): \DOMDocument
    {
        $copy = new \DOMDocument('1.0', 'UTF-8');
        $clone = $document->cloneNode(true);

        if (! $clone instanceof \DOMDocument) {
            return $copy;
        }

        $copy = $clone;

        foreach (self::elements($copy, '//ext:UBLExtensions') as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (self::elements($copy, '//cac:Signature') as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (self::elements($copy, "//cac:AdditionalDocumentReference[cbc:ID='QR']") as $node) {
            $node->parentNode?->removeChild($node);
        }

        return $copy;
    }

    /** @return list<\DOMElement> */
    private static function elements(\DOMDocument $document, string $expression): array
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ext', Namespaces::EXT);
        $xpath->registerNamespace('cac', Namespaces::CAC);
        $xpath->registerNamespace('cbc', Namespaces::CBC);

        $result = $xpath->query($expression);

        if ($result === false) {
            throw new SigningException(sprintf('Invalid XPath expression: %s', $expression));
        }

        $elements = [];

        foreach ($result as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }
}
