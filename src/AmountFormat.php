<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

/**
 * How "amount" is rendered in a refund request body.
 *
 *   Number        "amount": 2.5                                   (accepted by the live API)
 *   String        "amount": "2.50"
 *   Object        "amount": {"value": 2.5, "currency": "AZN"}     (same shape as create payment)
 *   ObjectString  "amount": {"value": "2.50", "currency": "AZN"}
 */
enum AmountFormat: string
{
    case Number = 'number';
    case String = 'string';
    case Object = 'object';
    case ObjectString = 'object_string';

    public function render(Amount $amount): float|string|array
    {
        return match ($this) {
            self::Number => $amount->toJsonNumber(),
            self::String => $amount->toDecimalString(),
            self::Object => ['value' => $amount->toJsonNumber(), 'currency' => $amount->currency],
            self::ObjectString => ['value' => $amount->toDecimalString(), 'currency' => $amount->currency],
        };
    }
}
