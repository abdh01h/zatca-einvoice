<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

use Zatca\EInvoice\Domain\Invoice;

interface Rule
{
    public function id(): string;

    public function description(): string;

    public function severity(): Severity;

    /** Returns a human-readable message when the rule is broken, null when it passes. */
    public function check(Invoice $invoice): ?string;
}
