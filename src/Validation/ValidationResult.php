<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

use Zatca\EInvoice\Exception\ValidationFailed;

final readonly class ValidationResult
{
    /** @param list<Violation> $violations */
    public function __construct(public array $violations = [])
    {
    }

    /** @return list<Violation> */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn (Violation $violation): bool => $violation->severity === Severity::Error,
        ));
    }

    /** @return list<Violation> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn (Violation $violation): bool => $violation->severity === Severity::Warning,
        ));
    }

    public function errorCount(): int
    {
        return count($this->errors());
    }

    public function warningCount(): int
    {
        return count($this->warnings());
    }

    public function isValid(): bool
    {
        return $this->errorCount() === 0;
    }

    public function merge(self $other): self
    {
        return new self([...$this->violations, ...$other->violations]);
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (Violation $violation): string => $violation->toString(), $this->violations);
    }

    public function throwIfInvalid(): void
    {
        if (! $this->isValid()) {
            throw new ValidationFailed($this);
        }
    }
}
