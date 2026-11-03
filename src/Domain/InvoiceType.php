<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

/** UN/CEFACT 1001 document type codes used by ZATCA. */
enum InvoiceType: string
{
    case Invoice = '388';
    case CreditNote = '381';
    case DebitNote = '383';

    public function isNote(): bool
    {
        return $this !== self::Invoice;
    }

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Tax Invoice',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
        };
    }
}
