<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Domain;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/**
 * A structured postal address as required by ZATCA Phase 2.
 *
 * Phase 2 rejects free-text addresses: the building number, street, district
 * (city subdivision) and postal code must each be supplied separately, and the
 * country must be a two-letter ISO 3166-1 alpha-2 code.
 */
final readonly class Address
{
    public function __construct(
        public string $streetName,
        public string $buildingNumber,
        public string $citySubdivision, // district / neighbourhood
        public string $city,
        public string $postalCode,
        public string $countryCode = 'SA',
        public ?string $plotIdentification = null,
        public ?string $additionalNumber = null,
    ) {
        foreach ([
            'street name' => $streetName,
            'building number' => $buildingNumber,
            'city subdivision' => $citySubdivision,
            'city' => $city,
            'postal code' => $postalCode,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Address %s must not be empty.', $field));
            }
        }

        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new InvalidArgumentException(sprintf('Country code "%s" must be ISO 3166-1 alpha-2, e.g. "SA".', $countryCode));
        }

        if ($postalCode !== '' && preg_match('/^\d{5}$/', $postalCode) !== 1) {
            throw new InvalidArgumentException(sprintf('Postal code "%s" must be five digits for Saudi addresses.', $postalCode));
        }
    }
}
