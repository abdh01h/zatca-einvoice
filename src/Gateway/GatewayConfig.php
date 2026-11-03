<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

use Zatca\EInvoice\Exception\InvalidArgumentException;

/** Endpoint set and headers for one ZATCA environment. */
final readonly class GatewayConfig
{
    public const SANDBOX = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';
    public const PRODUCTION = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core';

    public function __construct(
        public string $baseUrl = self::SANDBOX,
        public string $acceptVersion = 'v2',
        public string $language = 'en',
    ) {
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new InvalidArgumentException('The gateway base URL must use https.');
        }
    }

    public static function sandbox(): self
    {
        return new self(self::SANDBOX);
    }

    public static function production(): self
    {
        return new self(self::PRODUCTION);
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /** @return array<string, string> */
    public function headers(string $contentType = 'application/json'): array
    {
        return [
            'Accept' => 'application/json',
            'Accept-Version' => $this->acceptVersion,
            'Accept-Language' => $this->language,
            'Content-Type' => $contentType,
        ];
    }
}
