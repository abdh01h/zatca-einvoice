<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

use Zatca\EInvoice\Domain\Invoice;

/**
 * A rule written as a closure.
 *
 * Keeping rules as data rather than one class per rule keeps the ruleset
 * readable in one place, while the Rule interface keeps them swappable.
 */
final readonly class CallableRule implements Rule
{
    /** @param \Closure(Invoice): ?string $check */
    public function __construct(
        private string $id,
        private string $description,
        private Severity $severity,
        private \Closure $check,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function severity(): Severity
    {
        return $this->severity;
    }

    public function check(Invoice $invoice): ?string
    {
        return ($this->check)($invoice);
    }
}
