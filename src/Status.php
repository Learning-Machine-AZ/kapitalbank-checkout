<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

/**
 * Status of a payment or refund object. The bank uses pending | succeeded | canceled;
 * anything else (e.g. "declined") maps to Unknown and the raw string stays on the object.
 */
enum Status: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';
    case Unknown = 'unknown';

    public static function fromBank(mixed $value): self
    {
        if (!is_string($value) || $value === '') {
            return self::Unknown;
        }
        $value = strtolower(trim($value));

        return self::tryFrom($value === 'cancelled' ? 'canceled' : $value) ?? self::Unknown;
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Canceled;
    }
}
