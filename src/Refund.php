<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

/** A refund object (POST /v1/refunds answer). */
final class Refund
{
    public function __construct(
        public readonly ?string $id,
        public readonly Status $status,
        public readonly ?string $rawStatus,
        public readonly ?string $paymentId,
        public readonly ?Amount $amount,
        public readonly ?string $rrn,
        /** Exactly what was sent (useful for audit logs). */
        public readonly array $requestBody,
        public readonly string $idempotencyKey,
        public readonly array $raw,
        /** The answer this object was read from (HTTP status, storage-safe helpers). */
        public readonly ?CheckoutResponse $response = null,
    ) {
    }

    public static function fromArray(array $data, array $requestBody, string $idempotencyKey, ?CheckoutResponse $response = null): self
    {
        $amount = null;
        if (is_array($data['amount'] ?? null)) {
            $currency = is_string($data['amount']['currency'] ?? null) ? $data['amount']['currency'] : 'AZN';
            $amount = Amount::fromBank($data['amount']['value'] ?? null, $currency);
        } elseif (isset($data['amount'])) {
            $amount = Amount::fromBank($data['amount']);
        }
        $paymentId = $data['originalId'] ?? $data['paymentId'] ?? null;

        return new self(
            id: is_scalar($data['id'] ?? null) ? (string) $data['id'] : null,
            status: Status::fromBank($data['status'] ?? null),
            rawStatus: is_string($data['status'] ?? null) ? $data['status'] : null,
            paymentId: is_scalar($paymentId) ? (string) $paymentId : null,
            amount: $amount,
            rrn: Payment::rrnOf($data),
            requestBody: $requestBody,
            idempotencyKey: $idempotencyKey,
            raw: $data,
            response: $response,
        );
    }
}
