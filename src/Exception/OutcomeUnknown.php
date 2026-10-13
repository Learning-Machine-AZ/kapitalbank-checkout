<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Exception;

use LearningMachine\KapitalBank\Checkout\CheckoutResponse;

/**
 * The request may or may not have reached the bank (timeout, dropped connection,
 * unreadable 2xx answer). Treat money as "in flight": reconcile via getPayment() or retry
 * with the SAME idempotency key, never with a new one.
 *
 * $response is set when an answer did arrive but could not be used (a 2xx body that is not a
 * JSON object, a payment without an id) — keep it for diagnosis. Null for Unreachable.
 */
class OutcomeUnknown extends KapitalBankException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?CheckoutResponse $response = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
