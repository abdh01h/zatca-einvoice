<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Xml;

/**
 * The document element to emit.
 *
 * ZATCA's own SDK samples use a single <Invoice> root for all three document
 * types, carrying 381 (credit note) or 383 (debit note) in
 * cbc:InvoiceTypeCode. That is the default here because it is what ZATCA's
 * validation pipeline sees in the samples. Native <CreditNote>/<DebitNote>
 * roots are supported for integrations whose counterparty expects them.
 */
enum RootElement: string
{
    case Invoice = 'Invoice';
    case CreditNote = 'CreditNote';
    case DebitNote = 'DebitNote';

    public function isNativeNote(): bool
    {
        return $this !== self::Invoice;
    }

    public function namespace(): string
    {
        return match ($this) {
            self::Invoice => Namespaces::INVOICE,
            self::CreditNote => Namespaces::CREDIT_NOTE,
            self::DebitNote => Namespaces::DEBIT_NOTE,
        };
    }

    public function typeCodeElement(): string
    {
        return match ($this) {
            self::Invoice => 'cbc:InvoiceTypeCode',
            self::CreditNote => 'cbc:CreditNoteTypeCode',
            self::DebitNote => 'cbc:DebitNoteTypeCode',
        };
    }

    public function lineElement(): string
    {
        return match ($this) {
            self::Invoice => 'cac:InvoiceLine',
            self::CreditNote => 'cac:CreditNoteLine',
            self::DebitNote => 'cac:DebitNoteLine',
        };
    }

    public function quantityElement(): string
    {
        return match ($this) {
            self::Invoice => 'cbc:InvoicedQuantity',
            self::CreditNote => 'cbc:CreditedQuantity',
            self::DebitNote => 'cbc:DebitedQuantity',
        };
    }

    public function schemaFile(): string
    {
        return match ($this) {
            self::Invoice => 'UBL-Invoice-2.1.xsd',
            self::CreditNote => 'UBL-CreditNote-2.1.xsd',
            self::DebitNote => 'UBL-DebitNote-2.1.xsd',
        };
    }
}
