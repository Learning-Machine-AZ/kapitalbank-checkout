<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Exception;

/** Webhook body is malformed, incomplete or its signature does not match. */
final class InvalidWebhook extends KapitalBankException
{
}
