<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    public function errorMessages(): array
    {
        $json = $this->json();
        $messages = [];

        $errors = $json['errors'] ?? null;

        if (! is_array($errors)) {
            $validation = $json['validationResults'] ?? null;
            $errors = is_array($validation) && is_array($validation['errorMessages'] ?? null)
                ? $validation['errorMessages']
                : [];
        }

        foreach ($errors as $error) {
            if (is_string($error)) {
                $messages[] = $error;
                continue;
            }

            if (is_array($error)) {
                $message = $error['message'] ?? null;
                $messages[] = is_string($message) ? $message : (string) json_encode($error);
            }
        }

        return $messages;
    }
}
