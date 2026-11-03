<?php

declare(strict_types=1);

/**
 * Published by `php artisan vendor:publish --tag=zatca-config`.
 *
 * The certificate and key are the CSID pair issued by ZATCA after onboarding;
 * they are secrets and belong in the environment, never in version control.
 */
return [
    'certificate_path' => env('ZATCA_CERTIFICATE_PATH'),
    'private_key_path' => env('ZATCA_PRIVATE_KEY_PATH'),

    // Invoice (ZATCA samples use a single Invoice root for 381/383 as well)
    'root_element' => env('ZATCA_ROOT_ELEMENT', 'Invoice'),

    // Sequential counter (ICV) storage; the integration supplies the value.
    'validate_before_signing' => true,
];
