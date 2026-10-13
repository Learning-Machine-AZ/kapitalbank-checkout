<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

/**
 * One answer of the checkout API as it arrived: HTTP status, decoded JSON object (or [] when
 * the body is not a JSON object) and the raw text of a non-JSON body.
 *
 * Every result carries one: Payment::$response, Refund::$response, BankError::$response and
 * OutcomeUnknown::$response (a 2xx answer that could not be used). It is the safe way to keep
 * an answer for audit / admin pages:
 *
 *   - forStorage() / encodeForStorage(): secret-looking keys redacted, card-like digit runs
 *     masked, size capped;
 *   - errorMessage(): a readable one-liner (message, debug details, validation errors and the
 *     bank's error trace id), scrubbed and capped at 255 characters.
 *
 * Error answers look like:
 *   {"id": "<error/trace uuid>", "code": "bad_request", "status": 400, "method": "POST",
 *    "path": "…/v1/refunds", "message": "Malformed JSON request", "timestamp": "…"}
 * and may carry "errors": [{"property", "message"}]. Their `id` is the bank's trace id, NOT an
 * object id, and `status` is the HTTP code — so id()/status() only read 2xx answers;
 * errorId() gives the trace id to quote to the bank.
 */
final class CheckoutResponse
{
    public const STORAGE_LIMIT = 8000;

    /** Keys whose values are never stored, whatever the bank puts there. */
    private const SECRET_KEYS = '/token|secret|password|passwd|authori[sz]ation|cookie|signature|cvv|cvc|pan$|card_?number|cardnumber/i';

    public function __construct(
        public readonly int $httpStatus,
        public readonly array $body,
        public readonly ?string $rawBody = null,
    ) {
    }

    /** Same rules as the client: a JSON object becomes $body, anything else stays raw text. */
    public static function fromRaw(int $httpStatus, string $raw): self
    {
        $json = json_decode($raw, true);

        return new self($httpStatus, is_array($json) ? $json : [], is_array($json) ? null : $raw);
    }

    public function successful(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    public function serverError(): bool
    {
        return $this->httpStatus >= 500;
    }

    /** `status` of a payment / refund object, lower-cased ("succeeded", "pending", "canceled"). */
    public function status(): ?string
    {
        if (!$this->successful()) {
            return null;
        }
        $status = $this->body['status'] ?? null;

        return is_scalar($status) && $status !== '' ? strtolower((string) $status) : null;
    }

    public function id(): ?string
    {
        if (!$this->successful()) {
            return null;
        }
        $id = $this->body['id'] ?? null;

        return is_scalar($id) && $id !== '' ? mb_substr((string) $id, 0, 64) : null;
    }

    /** The bank's trace id of an error answer (its `id`) — what bank support can look up. */
    public function errorId(): ?string
    {
        if ($this->successful()) {
            return null;
        }
        $id = $this->body['id'] ?? $this->body['traceId'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? mb_substr(self::scrub((string) $id), 0, 64) : null;
    }

    /** RRN when the bank sends one (`rrn` on the object or `authorizationDetail.rrn`). */
    public function rrn(): ?string
    {
        foreach ([$this->body['rrn'] ?? null, $this->body['authorizationDetail']['rrn'] ?? null] as $rrn) {
            if (is_scalar($rrn) && (string) $rrn !== '') {
                return mb_substr((string) $rrn, 0, 64);
            }
        }

        return null;
    }

    /** Amount of the object in minor units, when present as {"value": 3.00, "currency": "AZN"}. */
    public function amountMinor(): ?int
    {
        $value = $this->body['amount']['value'] ?? null;

        return is_numeric($value) ? (int) round(((float) $value) * 100) : null;
    }

    public function currency(): ?string
    {
        $currency = $this->body['amount']['currency'] ?? null;

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : null;
    }

    /** A short machine code (`code`, `errorCode`, `error`, `cancellationReason`), also on 2xx objects. */
    public function errorCode(): ?string
    {
        foreach (['code', 'errorCode', 'error', 'cancellationReason'] as $key) {
            $value = $this->body[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return mb_substr(self::scrub((string) $value), 0, 64);
            }
        }

        return null;
    }

    /**
     * A human-readable error: the message, validation details (`errors[].property: message`,
     * `debugMessage` / `details` when present) and the bank's error id, scrubbed of anything
     * that looks like a card number, at most 255 characters.
     */
    public function errorMessage(): ?string
    {
        $parts = [];
        foreach (['message', 'errorDescription', 'error_description', 'detail', 'title', 'description'] as $key) {
            $value = $this->body[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $parts[] = (string) $value;
                break;
            }
        }
        foreach (['debugMessage', 'details'] as $key) {
            $value = $this->body[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $parts[] = (string) $value;
            }
        }
        $errors = $this->body['errors'] ?? null;
        if (is_array($errors)) {
            foreach (array_slice($errors, 0, 5) as $error) {
                if (is_array($error)) {
                    $property = $error['property'] ?? $error['field'] ?? null;
                    $message = $error['message'] ?? $error['defaultMessage'] ?? null;
                    $parts[] = trim((is_scalar($property) ? $property . ': ' : '') . (is_scalar($message) ? (string) $message : ''), ': ');
                } elseif (is_scalar($error)) {
                    $parts[] = (string) $error;
                }
            }
        }
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        if ($parts === [] && !$this->successful() && $this->rawBody !== null && trim($this->rawBody) !== '') {
            $parts[] = trim(strip_tags($this->rawBody));
        }
        if ($parts === []) {
            return null;
        }

        $text = implode(' — ', $parts);
        if ($errorId = $this->errorId()) {
            $suffix = ' [bank error id ' . $errorId . ']';
            $text = mb_substr($text, 0, 255 - mb_strlen($suffix)) . $suffix;
        }

        return mb_substr(self::scrub($text), 0, 255);
    }

    /**
     * The whole answer as pretty JSON for storage: secret-looking keys redacted, card-like
     * numbers masked, capped at STORAGE_LIMIT characters. A non-JSON answer (proxy HTML page)
     * is kept as a scrubbed text excerpt; an empty one gives null.
     */
    public function forStorage(): ?string
    {
        if ($this->body !== []) {
            $json = json_encode(self::redact($this->body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } elseif ($this->rawBody !== null && trim($this->rawBody) !== '') {
            $json = trim($this->rawBody);
        } else {
            return null;
        }

        return mb_substr(self::scrub((string) $json), 0, self::STORAGE_LIMIT);
    }

    /** Same treatment for any array to be stored (e.g. the request body that was sent). */
    public static function encodeForStorage(array $data): string
    {
        $json = json_encode(self::redact($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return mb_substr(self::scrub((string) $json), 0, self::STORAGE_LIMIT);
    }

    /**
     * Masks digit runs of 12+ (PANs, even with spaces/dashes). UUIDs are left intact — their
     * digit groups joined by dashes would otherwise look like a card number.
     */
    public static function scrub(string $text): string
    {
        $parts = preg_split('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return (string) preg_replace('/\d(?:[ -]?\d){11,}/', '[masked]', $text);
        }
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $parts[$i] = (string) preg_replace('/\d(?:[ -]?\d){11,}/', '[masked]', $part);
            }
        }

        return implode('', $parts);
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
