<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

/** One broken rule, with the rule identifier a developer can look up. */
final readonly class Violation implements \Stringable
{
    public function __construct(
        public string $ruleId,
        public string $message,
        public Severity $severity = Severity::Error,
        public ?string $path = null,
    ) {
    }

    public function toString(): string
    {
        return sprintf('[%s] %s%s', $this->ruleId, $this->message, $this->path === null ? '' : ' ('.$this->path.')');
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
