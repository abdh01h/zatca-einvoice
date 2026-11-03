<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Crypto;

use Zatca\EInvoice\Domain\Invoice;
use Zatca\EInvoice\Qr\Tlv;

/**
 * A signed invoice: the DOM, the hash, the signature and the QR payload.
 *
 * The DOM is kept (rather than a string) so callers can post-process or
 * submit it, and so tests can re-hash the exact bytes that were signed.
 */
final readonly class SignedDocument
{
    /**
     * @param string $xml the exact bytes that were hashed and signed; this is
     *                    what must be submitted to ZATCA, byte for byte
     */
    public function __construct(
        public \DOMDocument $document,
        public Invoice $invoice,
        public InvoiceHash $hash,
        public string $signatureValue,
        public string $qrPayload,
        public string $xml,
    ) {
    }

    /** The submittable document: identical to the bytes that were signed. */
    public function xml(): string
    {
        return $this->xml;
    }

    /**
     * Indented copy for humans (logs, diffs, docs).
     *
     * Never submit this: indentation adds whitespace text nodes, which change
     * the canonical form and therefore break the hash and the signature.
     */
    public function xmlForDisplay(): string
    {
        $copy = new \DOMDocument('1.0', 'UTF-8');
        $copy->loadXML($this->xml);
        $copy->preserveWhiteSpace = false;
        $copy->formatOutput = true;

        $pretty = $copy->saveXML();

        return $pretty === false ? $this->xml : $pretty;
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->xml);
    }

    public function saveForDisplay(string $path): void
    {
        file_put_contents($path, $this->xmlForDisplay());
    }

    /** @return array<int, string> decoded QR tags */
    public function qrFields(): array
    {
        return Tlv::decode($this->qrPayload);
    }
}
