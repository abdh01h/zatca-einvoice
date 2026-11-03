<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

final readonly class Request
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public string $body = '',
    ) {
    }

    /** @return array<int, mixed> */
    public function toCurlOptions(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[] = $name.': '.$value;
        }

        return [
            CURLOPT_URL => $this->url,
            CURLOPT_CUSTOMREQUEST => $this->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $this->body,
            CURLOPT_RETURNTRANSFER => true,
        ];
    }
}
