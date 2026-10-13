<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Exception;

/** Network error or timeout talking to the bank. Outcome unknown. */
final class Unreachable extends OutcomeUnknown
{
}
