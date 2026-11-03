<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Exception;

use Zatca\EInvoice\Validation\ValidationResult;

final class ValidationFailed extends ZatcaException
{
    public function __construct(public readonly ValidationResult $result)
    {
        parent::__construct(sprintf(
            'Invoice failed validation with %d error(s): %s',
            $result->errorCount(),
            implode('; ', array_map(
                static fn ($violation): string => $violation->toString(),
                $result->errors(),
            )),
        ));
    }
}
