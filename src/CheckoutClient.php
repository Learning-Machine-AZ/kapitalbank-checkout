<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use LearningMachine\KapitalBank\Checkout\Exception\AuthenticationFailed;
use LearningMachine\KapitalBank\Checkout\Exception\BankRejected;
use LearningMachine\KapitalBank\Checkout\Exception\ConfigurationException;
use LearningMachine\KapitalBank\Checkout\Exception\OutcomeUnknown;
use LearningMachine\KapitalBank\Checkout\Exception\Unreachable;

/**
 * Kapital Bank / Birbank Checkout API client.
 *
 *   POST {base}/oauth2/token        form: scope, grant_type=client_credentials, client_id, client_secret
 *   POST {base}/v1/payments         create payment (Bearer token, X-Idempotency-Key)
 *   GET  {base}/v1/payments/{id}    payment status
 *   POST {base}/v1/refunds          refund (Bearer token, X-Idempotency-Key)
 */
final class CheckoutClient
{
    private ?string $token = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * Builds a client from a plain array (see Config::fromArray). Without an explicit PSR-18
     * client, Guzzle is used if installed, else php-http/discovery.
     */
    public static function fromConfig(
        array|Config $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?CacheInterface $cache = null,
    ): self {
        $config = $config instanceof Config ? $config : Config::fromArray($config);

        if ($http === null) {
            if (class_exists(\GuzzleHttp\Client::class)) {
                $http = new \GuzzleHttp\Client([
                    'timeout' => $config->timeout,
                    'connect_timeout' => $config->connectTimeout,
                ]);
            } elseif (class_exists(\Http\Discovery\Psr18ClientDiscovery::class)) {
                $http = \Http\Discovery\Psr18ClientDiscovery::find();
            } else {
                throw new ConfigurationException('No PSR-18 HTTP client: install guzzlehttp/guzzle or php-http/discovery, or pass one.');
            }
        }
        if ($requestFactory === null || $streamFactory === null) {
            if (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
                $factory = new \GuzzleHttp\Psr7\HttpFactory();
            } elseif (class_exists(\Http\Discovery\Psr17FactoryDiscovery::class)) {
                $factory = null;
                $requestFactory ??= \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory();
                $streamFactory ??= \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory();
            } else {
                throw new ConfigurationException('No PSR-17 factories: install guzzlehttp/psr7 or php-http/discovery, or pass them.');
            }
            $requestFactory ??= $factory;
            $streamFactory ??= $factory;
        }

        return new self($config, $http, $requestFactory, $streamFactory, $cache);
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Creates a payment and returns it with redirectUrl() ready for the customer.
     * Without a key a random UUID v4 is generated; keep it — it is part of the redirect link
     * and the only safe way to retry after OutcomeUnknown.
     */
    public function createPayment(Amount $amount, string $description, string $returnUrl, ?string $idempotencyKey = null): Payment
    {
        $idempotencyKey ??= self::uuid4();
        $body = $this->paymentBody($amount, $description, $returnUrl);
        $response = $this->send('POST', $this->config->paymentsPath, $body, $idempotencyKey);
        if (!isset($response->body['id']) || !is_scalar($response->body['id'])) {
            throw new OutcomeUnknown('Payment answer has no id; check the payment before retrying.', 0, null, $response);
        }

        return Payment::fromArray($response->body, $idempotencyKey, $response);
    }

    /** The exact JSON body createPayment() sends. */
    public function paymentBody(Amount $amount, string $description, string $returnUrl): array
    {
        return [
            'amount' => ['value' => $amount->toJsonNumber(), 'currency' => $amount->currency],
            'capture' => true,
            'description' => $description,
            'paymentMethodData' => ['type' => $this->config->paymentMethodType],
            'confirmation' => ['type' => 'REDIRECT', 'returnUrl' => $returnUrl],
            'posDetail' => ['merchantId' => $this->config->merchantId, 'terminalId' => $this->config->terminalId],
        ];
    }

    public function getPayment(string $paymentId): Payment
    {
        $response = $this->send('GET', rtrim($this->config->paymentsPath, '/') . '/' . rawurlencode($paymentId));
        if (!isset($response->body['id']) || !is_scalar($response->body['id'])) {
            throw new OutcomeUnknown('Payment answer has no id.', 0, null, $response);
        }

        return Payment::fromArray($response->body, null, $response);
    }

    /**
     * Refunds (part of) a payment back to the card. Use a stable idempotency key per refund
     * (e.g. your refund row uuid) so a retry can never refund twice.
     *
     * @param RefundOptions|null $options overrides the configured body shape for this call
     */
    public function refund(string $paymentId, Amount $amount, string $idempotencyKey, ?RefundOptions $options = null, ?string $description = null): Refund
    {
        $body = $this->refundBody($paymentId, $amount, $options, $description);
        $response = $this->send('POST', $this->config->refundsPath, $body, $idempotencyKey);

        return Refund::fromArray($response->body, $body, $idempotencyKey, $response);
    }

    /**
     * The exact JSON body refund() sends. Default (the shape the live API accepts):
     * {"id": "<payment uuid>", "amount": 2.5}
     */
    public function refundBody(string $paymentId, Amount $amount, ?RefundOptions $options = null, ?string $description = null): array
    {
        return ($options ?? $this->config->refund)->body($paymentId, $amount, $this->config->merchantId, $this->config->terminalId, $description);
    }

    /** Current access token (from memory, PSR-16 cache, or a fresh token request). */
    public function accessToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $key = $this->cacheKey();
        if ($this->cache !== null) {
            $cached = $this->cache->get($key);
            if (is_string($cached) && $cached !== '') {
                return $this->token = $cached;
            }
        }

        $form = http_build_query([
            'scope' => $this->config->tokenScope,
            'grant_type' => 'client_credentials',
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
        ]);
        $url = $this->config->url($this->config->tokenPath);
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream($form));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // Nothing money-related was sent yet.
            throw new AuthenticationFailed('Could not reach the bank to authenticate: ' . $e->getMessage(), null, $e);
        }

        $json = json_decode((string) $response->getBody(), true);
        $token = is_array($json) ? ($json['access_token'] ?? null) : null;
        if ($response->getStatusCode() >= 300 || !is_string($token) || $token === '') {
            throw new AuthenticationFailed('The bank rejected the merchant authentication (HTTP ' . $response->getStatusCode() . ') [POST ' . $url . '].', $response->getStatusCode());
        }

        if ($this->cache !== null) {
            $ttl = is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] - 60 : 240;
            if ($ttl > 0) {
                $this->cache->set($key, $token, $ttl);
            }
        }

        return $this->token = $token;
    }

    public function forgetToken(): void
    {
        $this->token = null;
        $this->cache?->delete($this->cacheKey());
    }

    /**
     * @return CheckoutResponse a 2xx answer whose body is a JSON object
     * @throws AuthenticationFailed|BankRejected|OutcomeUnknown
     */
    private function send(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, bool $retried = false): CheckoutResponse
    {
        $url = $this->config->url($path);
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken());
        if ($idempotencyKey !== null) {
            $request = $request->withHeader('X-Idempotency-Key', $idempotencyKey);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(
                    json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                ));
        }

        $response = $this->transmit($request);
        $status = $response->getStatusCode();

        // Expired / revoked cached token: a 401 means the request was not processed.
        if ($status === 401 && !$retried && $this->config->retryOnUnauthorized) {
            $this->forgetToken();

            return $this->send($method, $path, $body, $idempotencyKey, true);
        }

        $answer = CheckoutResponse::fromRaw($status, (string) $response->getBody());

        if ($status < 200 || $status >= 300) {
            throw new BankRejected(BankError::fromResponse($status, $answer->body, $answer->rawBody), $method . ' ' . $url);
        }
        if ($answer->rawBody !== null) {
            throw new OutcomeUnknown("The bank answered HTTP {$status} with a body that is not a JSON object [{$method} {$url}].", 0, null, $answer);
        }

        return $answer;
    }

    private function transmit(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            throw new Unreachable('No answer from the bank (timeout or connection error): ' . $e->getMessage(), 0, $e);
        } catch (ClientExceptionInterface $e) {
            throw new Unreachable('HTTP client error talking to the bank: ' . $e->getMessage(), 0, $e);
        }
    }

    private function cacheKey(): string
    {
        return $this->config->cachePrefix . sha1($this->config->baseUrl . '|' . $this->config->clientId);
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
