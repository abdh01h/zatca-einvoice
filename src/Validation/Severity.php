<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Validation;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';

    public function weight(): int
    {
        return $this === self::Error ? 2 : 1;
    }
}
