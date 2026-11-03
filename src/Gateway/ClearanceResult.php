<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

/** What came back from clearance or reporting. */
final readonly class ClearanceResult
{
    /**
     * @param list<string> $errors
     * @param array<string, mixed> $payload raw JSON, for fields this class does not model yet
     */
    public function __construct(
        public string $status,
        public array $errors,
        public ?string $clearedInvoiceXml,
        public ?string $qrPayload,
        public string $requestId,
        public array $payload = [],
    ) {
    }

    public function isAccepted(): bool
    {
        return in_array(strtoupper($this->status), ['CLEARED', 'REPORTED'], true);
    }
}
