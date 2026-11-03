<?php

declare(strict_types=1);

namespace Zatca\EInvoice\Tests\Support;

use Zatca\EInvoice\Gateway\HttpClient;
use Zatca\EInvoice\Gateway\Request;
use Zatca\EInvoice\Gateway\Response;

/** Records outgoing requests and returns queued responses. */
final class StubHttpClient implements HttpClient
{
    /** @var list<Request> */
    public array $requests = [];

    /** @param list<Response> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function queue(Response $response): void
    {
        $this->responses[] = $response;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        $response = array_shift($this->responses);

        if (! $response instanceof Response) {
            throw new \RuntimeException('No stub response queued for '.$request->url);
        }

        return $response;
    }

    public function lastRequest(): ?Request
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }
}
