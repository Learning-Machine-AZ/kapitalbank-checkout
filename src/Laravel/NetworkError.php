<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Laravel;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** No answer (connection error / timeout) from Laravel's HTTP client, as PSR-18 expects it. */
final class NetworkError extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
