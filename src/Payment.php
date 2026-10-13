<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

/** A payment object (POST /v1/payments answer or GET /v1/payments/{id}). */
final class Payment
{
    public function __construct(
        public readonly string $id,
        public readonly Status $status,
        public readonly ?string $rawStatus,
        public readonly ?Amount $amount,
        public readonly ?string $description,
        public readonly ?string $confirmUrl,
        public readonly ?string $rrn,
        /** The X-Idempotency-Key the payment was created with (only on createPayment results). */
        public readonly ?string $idempotencyKey,
        public readonly array $raw,
        /** The answer this object was read from (HTTP status, storage-safe helpers). */
        public readonly ?CheckoutResponse $response = null,
    ) {
    }

    public static function fromArray(array $data, ?string $idempotencyKey = null, ?CheckoutResponse $response = null): self
    {
        $amount = null;
        if (is_array($data['amount'] ?? null)) {
            $currency = is_string($data['amount']['currency'] ?? null) ? $data['amount']['currency'] : 'AZN';
            $amount = Amount::fromBank($data['amount']['value'] ?? null, $currency);
        }

        return new self(
            id: (string) $data['id'],
            status: Status::fromBank($data['status'] ?? null),
            rawStatus: is_string($data['status'] ?? null) ? $data['status'] : null,
            amount: $amount,
            description: is_scalar($data['description'] ?? null) ? (string) $data['description'] : null,
            confirmUrl: is_string($data['confirmation']['confirmUrl'] ?? null) ? $data['confirmation']['confirmUrl'] : null,
            rrn: self::rrnOf($data),
            idempotencyKey: $idempotencyKey,
            raw: $data,
            response: $response,
        );
    }

    /**
     * Where to send the customer: confirmUrl?id=<payment id>&password=<idempotency key>
     * (the link format the bank's hosted page accepts). Null when there is no confirmUrl
     * or the key is unknown (payments loaded with getPayment()).
     */
    public function redirectUrl(?string $idempotencyKey = null): ?string
    {
        $key = $idempotencyKey ?? $this->idempotencyKey;
        if ($this->confirmUrl === null || $key === null) {
            return null;
        }
        $sep = str_contains($this->confirmUrl, '?') ? '&' : '?';

        return $this->confirmUrl . $sep . http_build_query(['id' => $this->id, 'password' => $key]);
    }

    /** @internal */
    public static function rrnOf(array $data): ?string
    {
        foreach ([$data['rrn'] ?? null, $data['authorizationDetail']['rrn'] ?? null] as $rrn) {
            if (is_scalar($rrn) && (string) $rrn !== '') {
                return (string) $rrn;
            }
        }

        return null;
    }
}
