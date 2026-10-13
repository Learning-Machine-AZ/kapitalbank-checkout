<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

use LearningMachine\KapitalBank\Checkout\Exception\ConfigurationException;

final class Config
{
    public const PRODUCTION_URL = 'https://checkout.kapitalbank.az/api';
    public const PREPROD_URL = 'https://precheckout.kapitalbank.az/api';

    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $merchantId,
        public readonly string $terminalId,
        public readonly string $baseUrl = self::PRODUCTION_URL,
        public readonly RefundOptions $refund = new RefundOptions(),
        public readonly float $timeout = 30.0,
        public readonly float $connectTimeout = 10.0,
        public readonly string $tokenScope = 'email',
        public readonly string $paymentMethodType = 'BIRBANK',
        public readonly string $tokenPath = '/oauth2/token',
        public readonly string $paymentsPath = '/v1/payments',
        public readonly string $refundsPath = '/v1/refunds',
        public readonly string $cachePrefix = 'kapitalbank_checkout_token_',
        /**
         * A 401 on an API call drops the token and retries once with a fresh one. False: the
         * 401 is reported as BankRejected like any other refusal (no second request).
         */
        public readonly bool $retryOnUnauthorized = true,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new ConfigurationException('Kapital checkout client_id / client_secret are not configured.', $clientId === '' ? 'client_id' : 'client_secret');
        }
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new ConfigurationException("Kapital checkout base_url \"{$baseUrl}\" is not a valid URL.", 'base_url');
        }
    }

    /**
     * Keys: client_id, client_secret, merchant_id, terminal_id, base_url (or environment:
     * "production" | "preprod"), timeout, connect_timeout, scope, payment_method_type,
     * refund (array, see RefundOptions::fromArray), token_path, payments_path, refunds_path,
     * retry_on_unauthorized (bool, default true).
     */
    public static function fromArray(array $c): self
    {
        $base = (string) ($c['base_url'] ?? '');
        if ($base === '') {
            $base = match (strtolower((string) ($c['environment'] ?? 'production'))) {
                'preprod', 'test', 'sandbox' => self::PREPROD_URL,
                default => self::PRODUCTION_URL,
            };
        }

        return new self(
            clientId: (string) ($c['client_id'] ?? ''),
            clientSecret: (string) ($c['client_secret'] ?? ''),
            merchantId: (string) ($c['merchant_id'] ?? $c['merchantId'] ?? ''),
            terminalId: (string) ($c['terminal_id'] ?? $c['terminalId'] ?? ''),
            baseUrl: rtrim($base, '/'),
            refund: isset($c['refund']) && is_array($c['refund']) ? RefundOptions::fromArray($c['refund']) : new RefundOptions(),
            timeout: max(1.0, (float) ($c['timeout'] ?? 30)),
            connectTimeout: max(1.0, (float) ($c['connect_timeout'] ?? 10)),
            tokenScope: (string) ($c['scope'] ?? 'email'),
            paymentMethodType: (string) ($c['payment_method_type'] ?? 'BIRBANK'),
            tokenPath: self::path($c['token_path'] ?? null, '/oauth2/token'),
            paymentsPath: self::path($c['payments_path'] ?? null, '/v1/payments'),
            refundsPath: self::path($c['refunds_path'] ?? null, '/v1/refunds'),
            retryOnUnauthorized: filter_var($c['retry_on_unauthorized'] ?? true, FILTER_VALIDATE_BOOL),
        );
    }

    private static function path(mixed $value, string $default): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : $default;
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
