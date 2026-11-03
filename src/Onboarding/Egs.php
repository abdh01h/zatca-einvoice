<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Onboarding;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * The E-invoice Generation Solution (EGS) a CSID is issued for: the device or
 * software unit that signs invoices, described the way ZATCA's CSR profile
 * requires.
 */
final readonly class Egs
{
    public function __construct(
        public string $manufacturer,
        public string $model,
        public string $serial,
        public string $organizationName,
        public string $branchName,
        public string $commonNamePrefix = 'TST',
    ) {
        foreach ([
            'manufacturer' => $manufacturer,
            'model' => $model,
            'serial' => $serial,
            'organization' => $organizationName,
            'branch' => $branchName,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('The EGS %s must not be empty.', $field));
            }
        }

        if (preg_match('/^[A-Z]{3}$/', $commonNamePrefix) !== 1) {
            throw new InvalidArgumentException('The CN prefix is three characters: TST for compliance, PRD for production.');
        }
    }

    /** KSA-2 style serial: 1-manufacturer|2-model|3-serial. */
    public function serialNumberAttribute(): string
    {
        return sprintf('1-%s|2-%s|3-%s', $this->manufacturer, $this->model, $this->serial);
    }

    public function commonName(): string
    {
        return $this->commonNamePrefix.'-'.$this->serial;
    }
}
