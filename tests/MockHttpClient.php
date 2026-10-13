<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Queue of canned responses (or Throwables); records every request. No network. */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var list<ResponseInterface|\Throwable> */
    private array $queue = [];

    public function push(ResponseInterface|\Throwable $item): self
    {
        $this->queue[] = $item;

        return $this;
    }

    public function json(int $status, array $body): self
    {
        return $this->push(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    public function token(string $token = 'tkn-1', int $expiresIn = 300): self
    {
        return $this->json(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new \LogicException('Unexpected request: ' . $request->getMethod() . ' ' . $request->getUri());
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function bodyOf(int $i): array
    {
        return json_decode((string) $this->requests[$i]->getBody(), true);
    }
}
