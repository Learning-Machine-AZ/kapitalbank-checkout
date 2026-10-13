<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Exception;

/**
 * Invalid or missing configuration. Nothing was sent to the bank.
 *
 * $key names the offending setting (e.g. "client_id", "base_url", "refund.amount_format",
 * "refund.extra_json", "refund.id_field") so an application can word the error in terms of
 * its own environment variables.
 */
final class ConfigurationException extends KapitalBankException
{
    public function __construct(string $message, public readonly ?string $key = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
