<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Exception;

/**
 * The OAuth2 token request failed (rejected credentials or no answer). No payment or
 * refund request was sent, so retrying later is safe.
 */
final class AuthenticationFailed extends KapitalBankException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
