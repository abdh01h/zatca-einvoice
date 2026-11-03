<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

use Zatca\EInvoice\Exception\ZatcaException;

final class GatewayException extends ZatcaException
{
    /** @param list<string> $messages */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $messages = [],
        public readonly string $body = '',
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(string $operation, Response $response): self
    {
        $details = $response->errorMessages();
        $suffix = $details === [] ? '' : ' — '.implode('; ', array_slice($details, 0, 3));

        return new self(
            sprintf('%s failed with HTTP %d%s', $operation, $response->status, $suffix),
            $response->status,
            $details,
            $response->body,
        );
    }
}
