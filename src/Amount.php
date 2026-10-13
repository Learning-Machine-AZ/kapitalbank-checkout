<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

use InvalidArgumentException;

/**
 * Money as integer minor units (qəpik for AZN) + ISO currency. No floats inside:
 * decimals are parsed from strings and rendered as strings; a float is produced only at
 * the very edge, for a JSON number the bank expects ("amount": 2.5).
 */
final class Amount
{
    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {
        if ($minor < 0) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid currency code \"{$currency}\".");
        }
    }

    public static function fromMinor(int $minor, string $currency = 'AZN'): self
    {
        return new self($minor, strtoupper($currency));
    }

    /**
     * "2.5", "2.50", "3", 3 → minor units. More than 2 decimals is rejected, not rounded.
     */
    public static function fromDecimal(string|int $value, string $currency = 'AZN'): self
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid decimal amount \"{$value}\" (expected e.g. 2.50).");
        }
        $minor = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return new self($minor, strtoupper($currency));
    }

    /**
     * Parses an amount the bank sent (JSON number or string). Bank numbers have at most two
     * decimals; they are rounded to the nearest minor unit to absorb float noise. Anything that
     * cannot be read as a non-negative amount in a valid currency gives null (never throws:
     * an odd field in an answer must not hide the rest of it).
     */
    public static function fromBank(mixed $value, string $currency = 'AZN'): ?self
    {
        try {
            if (is_int($value)) {
                return self::fromMinor($value * 100, $currency);
            }
            if (is_float($value)) {
                return self::fromMinor((int) round($value * 100), $currency);
            }
            if (is_string($value) && is_numeric($value)) {
                return preg_match('/^\d+(\.\d{1,2})?$/', trim($value))
                    ? self::fromDecimal($value, $currency)
                    : self::fromMinor((int) round(((float) $value) * 100), $currency);
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        return null;
    }

    /** "2.50" */
    public function toDecimalString(): string
    {
        return intdiv($this->minor, 100) . '.' . str_pad((string) ($this->minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 2.5 — only for the JSON body; exact for any 2-decimal value. */
    public function toJsonNumber(): float
    {
        return (float) $this->toDecimalString();
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function __toString(): string
    {
        return $this->toDecimalString() . ' ' . $this->currency;
    }
}
