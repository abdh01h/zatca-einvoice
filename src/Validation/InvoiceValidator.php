<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

use Zatca\EInvoice\Domain\Invoice;

/** Runs a ruleset over an invoice and collects every violation, not the first. */
final class InvoiceValidator
{
    /** @param list<Rule> $rules */
    public function __construct(private readonly array $rules)
    {
    }

    public static function withDefaults(): self
    {
        return new self(ZatcaRules::default());
    }

    public function validate(Invoice $invoice): ValidationResult
    {
        $violations = [];

        foreach ($this->rules as $rule) {
            $message = $rule->check($invoice);

            if ($message !== null) {
                $violations[] = new Violation($rule->id(), $message, $rule->severity());
            }
        }

        return new ValidationResult($violations);
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }
}
