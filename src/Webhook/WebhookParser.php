<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Webhook;

use LearningMachine\KapitalBank\Checkout\Exception\InvalidWebhook;
use LearningMachine\KapitalBank\Checkout\Status;

/**
 * Parses and verifies the bank's webhook POST.
 *
 * Body: {"payload": {"id": "<payment id>", "type": "…", "paymentMethod": "…", "status": "succeeded"}, …}
 * Header X-Signature: base64(HMAC-SHA256(json_encode(payload, UNESCAPED_SLASHES|UNESCAPED_UNICODE), secret))
 *
 * Only `succeeded` means money arrived. `canceled` (or anything else) is a normal outcome
 * (customer backed out): answer HTTP 200 anyway, otherwise the bank keeps re-delivering.
 * Always re-check with getPayment() before crediting if you need extra assurance.
 */
final class WebhookParser
{
    public const SIGNATURE_HEADER = 'X-Signature';

    public function __construct(private readonly string $secret)
    {
        if ($secret === '') {
            throw new InvalidWebhook('Webhook secret is not configured.');
        }
    }

    /** Signature of a payload value (normally the inner "payload" object). */
    public function sign(mixed $payload): string
    {
        return base64_encode(hash_hmac('sha256', self::canonical($payload), $this->secret, true));
    }

    /**
     * True when the body is a JSON object with a "payload" key and $signature is exactly the
     * signature of that payload (constant-time compare, no trimming). Structure is checked by
     * parse() / parseUnverified(), not here.
     */
    public function verify(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data) || !array_key_exists('payload', $data)) {
            return false;
        }

        return hash_equals($this->sign($data['payload']), $signature);
    }

    /** @throws InvalidWebhook */
    public function parse(string $rawBody, ?string $signature): WebhookEvent
    {
        if (!$this->verify($rawBody, $signature)) {
            throw new InvalidWebhook('Invalid webhook signature or body.');
        }

        return self::parseUnverified($rawBody);
    }

    /** Structure check only, no signature. @throws InvalidWebhook */
    public static function parseUnverified(string $rawBody): WebhookEvent
    {
        $data = json_decode($rawBody, true);
        $p = is_array($data) ? ($data['payload'] ?? null) : null;
        if (!is_array($p)) {
            throw new InvalidWebhook('Webhook body is not JSON with a "payload" object.');
        }
        foreach (['id', 'type', 'status'] as $key) {
            if (!is_string($p[$key] ?? null) || $p[$key] === '') {
                throw new InvalidWebhook("Webhook payload is missing \"{$key}\".");
            }
        }
        $method = $p['paymentMethod'] ?? null;
        if ($method !== null && !is_string($method)) {
            throw new InvalidWebhook('Webhook payload "paymentMethod" must be a string.');
        }

        return new WebhookEvent($p['id'], $p['type'], $method, Status::fromBank($p['status']), $p['status'], $p);
    }

    private static function canonical(mixed $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
