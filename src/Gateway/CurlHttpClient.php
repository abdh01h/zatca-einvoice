<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

use Zatca\EInvoice\Exception\ZatcaException;

/** Default transport. Requires ext-curl, which the gateway uses only. */
final readonly class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeoutSeconds = 30)
    {
    }

    public function send(Request $request): Response
    {
        if (! function_exists('curl_init')) {
            throw new ZatcaException('ext-curl is required for the default HTTP transport; inject another HttpClient instead.');
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new ZatcaException('Could not initialise curl.');
        }

        $options = $request->toCurlOptions() + [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (! is_string($body)) {
            throw new ZatcaException('HTTP request failed: '.$error);
        }

        return new Response($status, $body);
    }
}
