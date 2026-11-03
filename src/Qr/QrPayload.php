<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Qr;

use Zatca\EInvoice\Crypto\InvoiceHash;
use Zatca\EInvoice\Domain\Invoice;

/**
 * ZATCA QR payloads.
 *
 * Phase 1 (generation) requires tags 1-5. Phase 2 (integration) extends the
 * same QR with the invoice hash, the ECDSA signature, the signing public key
 * and, once ZATCA has stamped a cleared invoice, its stamp.
 */
final class QrPayload
{
    public const TAG_SELLER_NAME = 1;
    public const TAG_VAT_NUMBER = 2;
    public const TAG_TIMESTAMP = 3;
    public const TAG_TOTAL_WITH_VAT = 4;
    public const TAG_VAT_TOTAL = 5;
    public const TAG_INVOICE_HASH = 6;
    public const TAG_SIGNATURE = 7;
    public const TAG_PUBLIC_KEY = 8;
    public const TAG_STAMP = 9;

    /** Phase 1: the five mandatory tags. */
    public static function phaseOne(Invoice $invoice): string
    {
        return Tlv::encode(self::header($invoice));
    }

    /**
     * Phase 2: header plus hash (base64 of the raw digest), signature,
     * public key, and optionally ZATCA's stamp from a clearance response.
     */
    public static function phaseTwo(
        Invoice $invoice,
        InvoiceHash $hash,
        string $signatureBase64,
        string $publicKeyBase64,
        ?string $stampBase64 = null,
    ): string {
        $fields = self::header($invoice);
        $fields[self::TAG_INVOICE_HASH] = $hash->base64();
        $fields[self::TAG_SIGNATURE] = $signatureBase64;
        $fields[self::TAG_PUBLIC_KEY] = $publicKeyBase64;

        if ($stampBase64 !== null && $stampBase64 !== '') {
            $fields[self::TAG_STAMP] = $stampBase64;
        }

        return Tlv::encode($fields);
    }

    /** @return array<int, string> */
    private static function header(Invoice $invoice): array
    {
        $totals = $invoice->totals();

        return [
            self::TAG_SELLER_NAME => $invoice->seller->registrationName,
            self::TAG_VAT_NUMBER => $invoice->sellerVatNumber()->value,
            self::TAG_TIMESTAMP => self::timestamp($invoice),
            self::TAG_TOTAL_WITH_VAT => $totals->taxInclusiveAmount->toDecimal(),
            self::TAG_VAT_TOTAL => $totals->taxAmount->toDecimal(),
        ];
    }

    private static function timestamp(Invoice $invoice): string
    {
        $time = $invoice->issueTime ?? $invoice->issueDate;
        $moment = new \DateTimeImmutable(
            $invoice->issueDate->format('Y-m-d').' '.$time->format('H:i:s'),
            $invoice->issueDate->getTimezone(),
        );

        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
