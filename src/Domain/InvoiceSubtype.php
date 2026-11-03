<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

/**
 * The `name` attribute ZATCA attaches to cbc:InvoiceTypeCode.
 *
 * Seven digits: digit 1 is 1 for a standard (B2B, cleared) document and 2 for
 * a simplified (B2C, reported) document; the remaining six are ZATCA's
 * third-party / nominal / export / summary / self-billed flags, all zero in
 * the common case.
 */
enum InvoiceSubtype: string
{
    case Standard = '0100000';
    case Simplified = '0200000';

    public function isSimplified(): bool
    {
        return $this === self::Simplified;
    }

    /**
     * Build the seven-digit name from the standard prefix plus ZATCA's flags.
     * Flag order follows the XML Implementation Standard: third party,
     * nominal supply, export, summary, self-billed.
     */
    public static function withFlags(self $base, bool $thirdParty = false, bool $nominal = false, bool $export = false, bool $summary = false, bool $selfBilled = false): string
    {
        $flags = ($thirdParty ? '1' : '0')
            .($nominal ? '1' : '0')
            .($export ? '1' : '0')
            .($summary ? '1' : '0')
            .($selfBilled ? '1' : '0');

        return substr($base->value, 0, 2).$flags;
    }
}
