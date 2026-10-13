<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Laravel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that sends through Laravel's HTTP client (the Http facade), so everything
 * Laravel offers for outgoing requests applies to the bank calls too: Http::fake() and
 * Http::preventStrayRequests() in tests, Http::recorded(), request events / Telescope.
 *
 * The factory is resolved from the facade on every request (unless one is passed), so a
 * fake installed after the client was built still applies.
 *
 * Timeouts are seconds; null keeps Laravel's default (30 s / connect 10 s), 0 means no limit.
 * A connection failure or timeout becomes a PSR-18 NetworkExceptionInterface (NetworkError).
 */
final class LaravelHttpClient implements ClientInterface
{
    /** Set by Laravel / Guzzle themselves; copying them would duplicate or contradict. */
    private const SKIP_HEADERS = ['host', 'content-length', 'content-type'];

    public function __construct(
        private readonly ?float $timeout = 30.0,
        private readonly ?float $connectTimeout = 10.0,
        private readonly ?Factory $factory = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        /** @var Factory $factory */
        $factory = $this->factory ?? Http::getFacadeRoot();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (!in_array(strtolower((string) $name), self::SKIP_HEADERS, true)) {
                $headers[(string) $name] = implode(', ', $values);
            }
        }

        $options = [];
        if ($this->timeout !== null) {
            $options['timeout'] = $this->timeout;
        }
        if ($this->connectTimeout !== null) {
            $options['connect_timeout'] = $this->connectTimeout;
        }

        $pending = $factory->withHeaders($headers)->withOptions($options);

        $body = (string) $request->getBody();
        if ($body !== '') {
            $pending = $pending->withBody($body, $request->getHeaderLine('Content-Type') ?: 'application/octet-stream');
        } elseif ($request->hasHeader('Content-Type')) {
            $pending = $pending->withHeaders(['Content-Type' => $request->getHeaderLine('Content-Type')]);
        }

        try {
            $response = $pending->send($request->getMethod(), (string) $request->getUri());
        } catch (ConnectionException $e) {
            throw new NetworkError($e->getMessage(), $request, $e);
        }

        return $response->toPsrResponse();
    }
}
