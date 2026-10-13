<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Exception;

use LearningMachine\KapitalBank\Checkout\BankError;

/**
 * The bank answered with a non-2xx status. For 4xx the request was refused and nothing
 * happened. For 5xx on a POST the outcome is uncertain: check the payment with
 * getPayment() before retrying (with the SAME idempotency key).
 *
 * The message ends with the request line ("[POST https://…/v1/payments]") so a log line
 * shows which call failed; it never contains tokens or request bodies.
 */
final class BankRejected extends KapitalBankException
{
    public function __construct(public readonly BankError $error, public readonly ?string $request = null)
    {
        parent::__construct(sprintf(
            'Kapital checkout rejected the request (HTTP %d%s): %s%s',
            $error->httpStatus,
            $error->code !== null ? ', ' . $error->code : '',
            $error->message ?? 'no message',
            $request !== null ? ' [' . $request . ']' : '',
        ), $error->httpStatus);
    }

    public function isServerError(): bool
    {
        return $this->error->httpStatus >= 500;
    }
}
