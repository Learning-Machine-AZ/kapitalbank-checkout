<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout;

/**
 * An error answer of the checkout API:
 *   {"id": "<trace uuid>", "code": "bad_request", "status": 400, "method": "POST",
 *    "path": "/api/v1/refunds", "message": "Malformed JSON request", "timestamp": "…",
 *    "errors": [{"property": "…", "message": "…"}]}
 * `id` is the bank's trace id of the error (quote it to bank support), not an object id.
 */
final class BankError
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?string $traceId,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?string $method,
        public readonly ?string $path,
        public readonly ?string $timestamp,
        /** @var list<array{property: ?string, message: ?string}> */
        public readonly array $errors,
        public readonly array $raw,
        public readonly ?string $rawBody,
        /** The whole answer (storage-safe helpers: forStorage(), errorMessage(), errorCode()). */
        public readonly ?CheckoutResponse $response = null,
    ) {
    }

    public static function fromResponse(int $httpStatus, array $json, ?string $rawBody): self
    {
        $str = static fn (mixed $v): ?string => is_scalar($v) && (string) $v !== '' ? (string) $v : null;

        $message = null;
        foreach (['message', 'error_description', 'errorDescription', 'detail', 'title'] as $key) {
            if (($message = $str($json[$key] ?? null)) !== null) {
                break;
            }
        }
        $code = null;
        foreach (['code', 'errorCode', 'error'] as $key) {
            if (($code = $str($json[$key] ?? null)) !== null) {
                break;
            }
        }
        $errors = [];
        foreach (is_array($json['errors'] ?? null) ? $json['errors'] : [] as $e) {
            if (is_array($e)) {
                $errors[] = [
                    'property' => $str($e['property'] ?? $e['field'] ?? null),
                    'message' => $str($e['message'] ?? $e['defaultMessage'] ?? null),
                ];
            } elseif ($str($e) !== null) {
                $errors[] = ['property' => null, 'message' => (string) $e];
            }
        }
        if ($message === null && $json === [] && $rawBody !== null && trim($rawBody) !== '') {
            $message = mb_substr(trim(strip_tags($rawBody)), 0, 500);
        }

        return new self(
            httpStatus: $httpStatus,
            traceId: $str($json['id'] ?? $json['traceId'] ?? null),
            code: $code,
            message: $message,
            method: $str($json['method'] ?? null),
            path: $str($json['path'] ?? null),
            timestamp: $str($json['timestamp'] ?? null),
            errors: $errors,
            raw: $json,
            rawBody: $json === [] ? $rawBody : null,
            response: new CheckoutResponse($httpStatus, $json, $rawBody),
        );
    }
}
