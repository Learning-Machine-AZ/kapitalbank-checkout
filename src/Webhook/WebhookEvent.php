<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Webhook;

use LearningMachine\KapitalBank\Checkout\Status;

/** The inner "payload" of a checkout webhook: {"id", "type", "paymentMethod", "status"}. */
final class WebhookEvent
{
    public function __construct(
        /** Payment id (the id createPayment() returned). */
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $paymentMethod,
        public readonly Status $status,
        public readonly string $rawStatus,
        public readonly array $payload,
    ) {
    }

    public function isSucceeded(): bool
    {
        return $this->status === Status::Succeeded;
    }
}
