<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

use Zatca\EInvoice\Domain\Money;
use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * Reconciles the numbers *in the document*.
 *
 * The invoice aggregate cannot go wrong here -- InvoiceCalculator computes the
 * totals once -- but a document that arrives from another system can, and an
 * XML that XSD-validates with a total that does not add up is exactly the class
 * of failure ZATCA rejects at clearance. This validator works on any UBL
 * invoice, not just documents produced by this library.
 */
final class DocumentValidator
{
    /** @return ValidationResult */
    public function validate(\DOMDocument $document): ValidationResult
    {
        $xpath = new \DOMXPath($document);
        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            return new ValidationResult([new Violation('BR-CO-10', 'The document has no root element.')]);
        }

        $violations = [];
        $lines = $this->elements($xpath, "./*[local-name()='InvoiceLine' or local-name()='CreditNoteLine' or local-name()='DebitNoteLine']", $root);

        if ($lines === []) {
            return new ValidationResult([new Violation('BR-CO-10', 'The document has no invoice lines.')]);
        }

        $lineTotal = 0;

        foreach ($lines as $line) {
            $amount = $this->amount($xpath, "./*[local-name()='LineExtensionAmount']", $line);

            if ($amount === null) {
                $violations[] = new Violation('BR-CO-10', 'A line has no cbc:LineExtensionAmount.');
                continue;
            }

            $lineTotal += $amount;
        }

        $total = $this->first($xpath, "./*[local-name()='LegalMonetaryTotal']", $root);

        if (! $total instanceof \DOMElement) {
            return new ValidationResult([...$violations, new Violation('BR-CO-13', 'The document has no cac:LegalMonetaryTotal.')]);
        }

        $lineExtension = $this->amount($xpath, "./*[local-name()='LineExtensionAmount']", $total);
        $taxExclusive = $this->amount($xpath, "./*[local-name()='TaxExclusiveAmount']", $total);
        $taxInclusive = $this->amount($xpath, "./*[local-name()='TaxInclusiveAmount']", $total);
        $allowance = $this->amount($xpath, "./*[local-name()='AllowanceTotalAmount']", $total) ?? 0;
        $charge = $this->amount($xpath, "./*[local-name()='ChargeTotalAmount']", $total) ?? 0;
        $prepaid = $this->amount($xpath, "./*[local-name()='PrepaidAmount']", $total) ?? 0;
        $payable = $this->amount($xpath, "./*[local-name()='PayableAmount']", $total);
        $tax = $this->amount($xpath, "./*[local-name()='TaxTotal']/*[local-name()='TaxAmount']", $root);

        if ($lineExtension === null || $taxExclusive === null || $taxInclusive === null || $payable === null || $tax === null) {
            return new ValidationResult([...$violations, new Violation('BR-CO-13', 'LegalMonetaryTotal or TaxTotal is missing a mandatory amount.')]);
        }

        if ($lineTotal !== $lineExtension) {
            $violations[] = new Violation('BR-CO-10', sprintf(
                'Sum of line net amounts is %s but cbc:LineExtensionAmount says %s.',
                $this->format($lineTotal),
                $this->format($lineExtension),
            ));
        }

        if ($lineExtension - $allowance + $charge !== $taxExclusive) {
            $violations[] = new Violation('BR-CO-13', sprintf(
                'Line extension %s minus allowances %s plus charges %s does not equal the tax-exclusive amount %s.',
                $this->format($lineExtension),
                $this->format($allowance),
                $this->format($charge),
                $this->format($taxExclusive),
            ));
        }

        if ($taxExclusive + $tax !== $taxInclusive) {
            $violations[] = new Violation('BR-CO-15', sprintf(
                'Tax-exclusive %s plus tax %s does not equal the tax-inclusive amount %s.',
                $this->format($taxExclusive),
                $this->format($tax),
                $this->format($taxInclusive),
            ));
        }

        if ($taxInclusive - $prepaid !== $payable) {
            $violations[] = new Violation('BR-CO-16', sprintf(
                'Tax-inclusive %s minus prepaid %s does not equal the payable amount %s.',
                $this->format($taxInclusive),
                $this->format($prepaid),
                $this->format($payable),
            ));
        }

        return new ValidationResult($violations);
    }

    private function first(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): ?\DOMElement
    {
        foreach ($this->elements($xpath, $expression, $context) as $element) {
            return $element;
        }

        return null;
    }

    /** @return list<\DOMElement> */
    private function elements(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): array
    {
        $result = $context === null ? $xpath->query($expression) : $xpath->query($expression, $context);

        if ($result === false) {
            return [];
        }

        $elements = [];

        foreach ($result as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function amount(\DOMXPath $xpath, string $expression, \DOMElement $context): ?int
    {
        $node = $this->first($xpath, $expression, $context);

        if (! $node instanceof \DOMElement) {
            return null;
        }

        try {
            return Money::fromDecimal(trim((string) $node->nodeValue))->minorUnits;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function format(int $minorUnits): string
    {
        return Money::of($minorUnits)->toDecimal();
    }
}
