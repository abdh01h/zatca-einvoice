<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * An immutable invoice aggregate.
 *
 * Everything ZATCA asks for in the document header lives here; line arithmetic
 * is delegated to {@see LineItem} and totals to {@see InvoiceCalculator}, so
 * the XML writer, the validator and the signer cannot disagree about numbers.
 */
final readonly class Invoice
{
    /** A UUID always exists: ZATCA requires one and there is no valid state without it. */
    public Uuid $uuid;

    /**
     * @param list<LineItem>         $lines
     * @param list<AllowanceCharge>  $adjustments document-level allowances and charges
     */
    public function __construct(
        public InvoiceType $type,
        public InvoiceSubtype $subtype,
        public \DateTimeImmutable $issueDate,
        public Party $seller,
        public ?Party $buyer,
        public array $lines,
        public ?string $invoiceNumber = null,
        ?Uuid $uuid = null,
        public int $counterValue = 1,
        public ?string $previousInvoiceHash = null,
        public Currency $currency = Currency::SAR,
        public array $adjustments = [],
        public ?\DateTimeImmutable $issueTime = null,
        public ?\DateTimeImmutable $supplyDate = null,
        public ?\DateTimeImmutable $deliveryDate = null,
        public ?string $paymentMeansCode = '10',
        public ?string $orderReference = null,
        public ?string $billingReferenceId = null,
        public ?string $billingReferenceNumber = null,
        public ?\DateTimeImmutable $billingReferenceIssueDate = null,
        public ?string $instructionNote = null,
        public ?Money $prepaidAmount = null,
        public ?string $invoiceTypeName = null,
        public ?string $qr = null,
        public ?string $note = null,
    ) {
        $this->uuid = $uuid ?? Uuid::v4();

        if ($lines === []) {
            throw new InvalidArgumentException('An invoice needs at least one line item.');
        }

        if ($counterValue < 1) {
            throw new InvalidArgumentException('The invoice counter value (ICV) starts at 1.');
        }

        if ($seller->vatNumber === null) {
            throw new InvalidArgumentException('The seller must have a VAT registration number.');
        }

        if ($type->isNote() && $billingReferenceId === null) {
            throw new InvalidArgumentException(sprintf('%s must reference the invoice it corrects.', $type->label()));
        }

        if ($prepaidAmount !== null && $prepaidAmount->currency !== $currency) {
            throw new InvalidArgumentException('Prepaid amount must share the invoice currency.');
        }

        // Mixing currencies inside one invoice is unrepresentable: every amount
        // in the document is rendered with the document currency attribute.
        foreach ($lines as $line) {
            if ($line->unitPrice->currency !== $currency) {
                throw new InvalidArgumentException(sprintf(
                    'Line "%s" is priced in %s but the invoice currency is %s.',
                    $line->name,
                    $line->unitPrice->currency->value,
                    $currency->value,
                ));
            }
        }

        foreach ($adjustments as $adjustment) {
            if ($adjustment->amount->currency !== $currency) {
                throw new InvalidArgumentException('A document-level allowance or charge uses a different currency than the invoice.');
            }
        }
    }

    /**
     * Convenience constructor that fills the identifier, time and ICV defaults.
     *
     * @param list<LineItem> $lines
     */
    public static function draft(
        InvoiceType $type,
        InvoiceSubtype $subtype,
        \DateTimeImmutable $issueDate,
        Party $seller,
        ?Party $buyer,
        array $lines,
    ): self {
        return new self(
            type: $type,
            subtype: $subtype,
            issueDate: $issueDate,
            seller: $seller,
            buyer: $buyer,
            lines: $lines,
            uuid: Uuid::v4(),
            issueTime: $issueDate,
        );
    }

    public function isSimplified(): bool
    {
        return $this->subtype->isSimplified();
    }

    /** The seven-digit name attribute ZATCA expects on cbc:InvoiceTypeCode. */
    public function typeNameAttribute(): string
    {
        return $this->invoiceTypeName ?? $this->subtype->value;
    }

    /** The seller VAT number, guaranteed present by the constructor. */
    public function sellerVatNumber(): VatNumber
    {
        return $this->seller->vatNumber ?? throw new InvalidArgumentException('The seller has no VAT number.');
    }

    public function documentId(): string
    {
        return $this->invoiceNumber ?? $this->uuid->value;
    }

    public function totals(): InvoiceTotals
    {
        return InvoiceCalculator::totals($this);
    }

    public function withUuid(Uuid $uuid): self
    {
        return $this->copy(uuid: $uuid);
    }

    public function withInvoiceNumber(string $invoiceNumber): self
    {
        return $this->copy(invoiceNumber: $invoiceNumber);
    }

    public function withCounterValue(int $counterValue): self
    {
        return $this->copy(counterValue: $counterValue);
    }

    public function withPreviousInvoiceHash(?string $hash): self
    {
        return $this->copy(previousInvoiceHash: $hash);
    }

    public function withQr(?string $qr): self
    {
        return $this->copy(qr: $qr);
    }

    public function withIssueTime(\DateTimeImmutable $issueTime): self
    {
        return $this->copy(issueTime: $issueTime);
    }

    private function copy(
        ?string $invoiceNumber = null,
        ?Uuid $uuid = null,
        ?int $counterValue = null,
        ?string $previousInvoiceHash = null,
        ?\DateTimeImmutable $issueTime = null,
        ?string $qr = null,
    ): self {
        return new self(
            type: $this->type,
            subtype: $this->subtype,
            issueDate: $this->issueDate,
            seller: $this->seller,
            buyer: $this->buyer,
            lines: $this->lines,
            invoiceNumber: $invoiceNumber ?? $this->invoiceNumber,
            uuid: $uuid ?? $this->uuid,
            counterValue: $counterValue ?? $this->counterValue,
            previousInvoiceHash: $previousInvoiceHash ?? $this->previousInvoiceHash,
            currency: $this->currency,
            adjustments: $this->adjustments,
            issueTime: $issueTime ?? $this->issueTime,
            supplyDate: $this->supplyDate,
            deliveryDate: $this->deliveryDate,
            paymentMeansCode: $this->paymentMeansCode,
            orderReference: $this->orderReference,
            billingReferenceId: $this->billingReferenceId,
            billingReferenceNumber: $this->billingReferenceNumber,
            billingReferenceIssueDate: $this->billingReferenceIssueDate,
            instructionNote: $this->instructionNote,
            prepaidAmount: $this->prepaidAmount,
            invoiceTypeName: $this->invoiceTypeName,
            qr: $qr ?? $this->qr,
            note: $this->note,
        );
    }
}
