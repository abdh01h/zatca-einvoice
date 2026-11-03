<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Gateway;

/**
 * A transport seam.
 *
 * The gateway is the one part of this library that needs a network, so it takes
 * an HTTP client rather than calling curl directly: tests drive it with a stub,
 * and applications can plug in their existing Guzzle/PSR-18 client.
 */
interface HttpClient
{
    public function send(Request $request): Response;
}
