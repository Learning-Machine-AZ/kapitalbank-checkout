# learning-machine/kapitalbank-checkout

PHP client for the **Kapital Bank / Birbank Checkout API**: OAuth2 token, create payment,
payment status, refunds, webhook verification. Framework-agnostic core (PSR-18 / PSR-17 /
PSR-16) with an optional, auto-discovered Laravel integration.

PHP >= 8.1. MIT licensed.

## Install

```bash
composer require learning-machine/kapitalbank-checkout
```

The package is on [Packagist](https://packagist.org/packages/learning-machine/kapitalbank-checkout).

A PSR-18 client is needed: Guzzle (`guzzlehttp/guzzle`, already present in Laravel) is used
automatically; otherwise install `php-http/discovery` + any PSR-18 client, or pass one.

## Usage (plain PHP)

```php
use LearningMachine\KapitalBank\Checkout\Amount;
use LearningMachine\KapitalBank\Checkout\{CheckoutClient, CheckoutResponse, RefundOptions};
use LearningMachine\KapitalBank\Checkout\Exception\{AuthenticationFailed, BankRejected, OutcomeUnknown};

$client = CheckoutClient::fromConfig([
    'base_url'      => 'https://precheckout.kapitalbank.az/api', // or omit for production
    'client_id'     => getenv('KAPITALBANK_CLIENT_ID'),
    'client_secret' => getenv('KAPITALBANK_CLIENT_SECRET'),
    'merchant_id'   => getenv('KAPITALBANK_MERCHANT_ID'),
    'terminal_id'   => getenv('KAPITALBANK_TERMINAL_ID'),
    // 'refund' => ['amount_format' => 'number', 'id_field' => 'id'], // defaults
], cache: $psr16Cache /* optional: token caching */);

// 1. Create a payment and redirect the customer
$payment = $client->createPayment(
    Amount::fromDecimal('12.50'),            // or Amount::fromMinor(1250); currency defaults to AZN
    'Order #123',
    'https://shop.example/payment/return',
    $idempotencyKey,                         // optional; a UUID v4 is generated if null
);
$payment->id;               // bank payment uuid — store it
$payment->idempotencyKey;   // store it too (it is part of the redirect link)
header('Location: ' . $payment->redirectUrl()); // confirmUrl?id=<id>&password=<key>

// 2. Status
$p = $client->getPayment($paymentId);
$p->status;   // Status::Pending | Succeeded | Canceled | Unknown (raw string in $p->rawStatus)
$p->amount;   // Amount|null
$p->rrn;      // string|null

// 3. Refund (full or partial)
try {
    $refund = $client->refund($paymentId, Amount::fromDecimal('2.50'), $refundRowUuid);
    $refund->status; $refund->rrn; $refund->id; $refund->requestBody; // what was sent
} catch (BankRejected $e) {
    $e->error->httpStatus; $e->error->code; $e->error->message; $e->error->traceId; // quote traceId to the bank
    $e->isServerError(); // 5xx: outcome uncertain -> check before retrying
} catch (OutcomeUnknown $e) {
    // timeout / connection drop / unreadable answer: the bank MAY have processed it.
    // Retry only with the SAME idempotency key, or reconcile via getPayment().
} catch (AuthenticationFailed $e) {
    // token request failed, nothing money-related was sent
}

// Preview the exact refund JSON (e.g. on an admin confirmation page)
$client->refundBody($paymentId, Amount::fromMinor(250)); // ['id' => '…', 'amount' => 2.5]
// …or without a client / credentials:
RefundOptions::fromArray($refundConfig)->body($paymentId, Amount::fromMinor(250), $merchantId, $terminalId);
```

### Keeping answers for audit: `CheckoutResponse`

Every result carries the answer it was read from: `$payment->response`, `$refund->response`,
`$e->error->response` (BankRejected) and `$e->response` on an `OutcomeUnknown` whose answer
arrived but could not be used (a 2xx body that is not a JSON object, a payment without an id;
null when there was no answer at all).

```php
$r = $refund->response;
$r->httpStatus;     // 201
$r->status();       // "succeeded" (2xx only)
$r->id(); $r->rrn(); $r->amountMinor(); $r->currency();
$r->errorCode();    // code | errorCode | error | cancellationReason
$r->errorMessage(); // "Malformed JSON request — amount: must be a number [bank error id …]" (≤ 255 chars)
$r->forStorage();   // pretty JSON: secret-looking keys redacted, card-like numbers masked, ≤ 8000 chars
CheckoutResponse::encodeForStorage($refund->requestBody); // same treatment for anything you store
CheckoutResponse::scrub($text);                          // masks 12+ digit runs, keeps UUIDs
```

### Webhook (bank callback)

```php
use LearningMachine\KapitalBank\Checkout\Webhook\WebhookParser;

$parser = new WebhookParser(getenv('KAPITALBANK_WEBHOOK_SECRET'));
$event  = $parser->parse($rawBody, $request->headers->get('X-Signature')); // throws InvalidWebhook
if ($event->isSucceeded()) {
    // credit order $event->id (idempotently!)
} else {
    // canceled etc. — close the order; still answer HTTP 200 or the bank re-delivers
}
```

Signature = `base64(HMAC-SHA256(json_encode(payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), secret))`
over the inner `payload` value, sent in `X-Signature`. `verify()` only checks the signature
(exact, constant-time compare; the body must be a JSON object with a `payload` key);
`parse()` also checks the structure (`id`, `type`, `status` strings).

## Laravel

The provider `LearningMachine\KapitalBank\Checkout\Laravel\KapitalBankServiceProvider` is
auto-discovered. Publish the config if you want to edit it:

```bash
php artisan vendor:publish --tag=kapitalbank-config
```

`.env`:

```dotenv
KAPITALBANK_BASE_URL=https://checkout.kapitalbank.az/api   # preprod: https://precheckout.kapitalbank.az/api
KAPITALBANK_CLIENT_ID=
KAPITALBANK_CLIENT_SECRET=
KAPITALBANK_MERCHANT_ID=
KAPITALBANK_TERMINAL_ID=
KAPITALBANK_WEBHOOK_SECRET=
# KAPITALBANK_TOKEN_CACHE_STORE=redis      # default store if empty, "false" disables
# KAPITALBANK_REFUND_ID_FIELD=id           # id | originalId | paymentId
# KAPITALBANK_REFUND_AMOUNT_FORMAT=number  # number | string | object | object_string
# KAPITALBANK_REFUND_SEND_DESCRIPTION=false
# KAPITALBANK_REFUND_SEND_POS_DETAIL=false
# KAPITALBANK_REFUND_EXTRA_JSON=           # JSON object merged last, e.g. {"currency":"AZN"}
```

Then inject `CheckoutClient` / `WebhookParser` anywhere (both are singletons).

Requests go through Laravel's HTTP client (`Laravel\LaravelHttpClient`, a PSR-18 adapter over
the `Http` facade), so `Http::fake()`, `Http::preventStrayRequests()` and `Http::recorded()`
work in your tests. Use it directly when you build the client yourself, e.g. with settings
from your own config keys:

```php
use LearningMachine\KapitalBank\Checkout\Laravel\LaravelHttpClient;

$client = CheckoutClient::fromConfig($settings, new LaravelHttpClient(timeout: 30, connectTimeout: 10));
// timeout / connectTimeout in seconds; null = Laravel's default, 0 = no limit
```

If you build the client from your own configuration, you can turn the auto-discovery off
(`"extra": {"laravel": {"dont-discover": ["learning-machine/kapitalbank-checkout"]}}`).

## Configuration keys (`Config::fromArray`)

| Key | Default | |
|---|---|---|
| `client_id`, `client_secret` | — | required |
| `merchant_id`, `terminal_id` | `''` | `posDetail` of payments (and of refunds with `send_pos_detail`) |
| `base_url` / `environment` | production | `environment: preprod` = `Config::PREPROD_URL` |
| `timeout`, `connect_timeout` | 30 / 10 | used when the client creates Guzzle itself |
| `token_path`, `payments_path`, `refunds_path` | `/oauth2/token`, `/v1/payments`, `/v1/refunds` | |
| `retry_on_unauthorized` | `true` | a 401 drops the token and retries once; `false` reports it as BankRejected |
| `scope`, `payment_method_type` | `email`, `BIRBANK` | |
| `refund` | see below | `id_field`, `amount_format`, `send_description`, `send_pos_detail`, `extra_json` / `extra` |

Invalid settings throw `ConfigurationException` before anything is sent; its `$key` names the
setting (`client_id`, `base_url`, `refund.amount_format`, `refund.extra_json`, …) so an
application can word the error in terms of its own environment variables.

## API notes

| Call | Request |
|---|---|
| Token | `POST {base}/oauth2/token`, form `scope=email&grant_type=client_credentials&client_id&client_secret` |
| Create payment | `POST {base}/v1/payments`, Bearer, `X-Idempotency-Key`, body `amount{value,currency}`, `capture:true`, `description`, `paymentMethodData{type:BIRBANK}`, `confirmation{type:REDIRECT,returnUrl}`, `posDetail{merchantId,terminalId}` |
| Payment status | `GET {base}/v1/payments/{id}` |
| Refund | `POST {base}/v1/refunds`, Bearer, `X-Idempotency-Key`, body `{"id": "<payment uuid>", "amount": 2.5}` |

- **Refund body**: the default `{"id", "amount": <decimal number>}` is the shape the live API
  accepts (the bank returns an RRN). The public reference documents only the refund object,
  not the request; `{"originalId", "amount": {"value", "currency"}}` is answered with 400
  "Malformed JSON request". Other shapes stay configurable (`RefundOptions` / `refund` config)
  in case the bank changes it.
- Base URLs: production `https://checkout.kapitalbank.az/api`, preprod
  `https://precheckout.kapitalbank.az/api` (`Config::PRODUCTION_URL` / `Config::PREPROD_URL`,
  or `'environment' => 'preprod'`).
- Statuses: `pending`, `succeeded`, `canceled` (`Status` enum; anything else is `Unknown` with
  the raw string kept). Only `succeeded` means money moved.
- Error answers: `{"id","code","status","method","path","message","timestamp"}` (+ optional
  `errors[]`) → `BankRejected::$error` (`BankError`). `id` there is the bank's trace id.
  The exception message ends with the request line, e.g. `[POST https://…/v1/payments]`
  (never tokens or bodies), so log lines show which call failed.
- Amounts are integer minor units internally (`Amount`), never floats; the JSON number is
  produced only when encoding the body (`5`, `2.5`).
- The token is cached in memory per client and, if a PSR-16 cache is given, for
  `expires_in - 60` seconds.
- Keep production credentials away from dev machines: the package does not block the
  production host by itself; guard that in the application if needed.

## Tests

```bash
composer install
vendor/bin/phpunit
```

No network: all HTTP goes through a mock PSR-18 client or a faked Laravel HTTP factory.
GitHub Actions runs the suite on PHP 8.1 – 8.4.

## Changelog

### 0.2.0

- `CheckoutResponse`: the answer behind every result (`Payment::$response`, `Refund::$response`,
  `BankError::$response`, `OutcomeUnknown::$response`) with HTTP status and storage-safe helpers
  (`forStorage()`, `encodeForStorage()`, `scrub()`, `errorMessage()`, `errorCode()`).
- `Laravel\LaravelHttpClient`: PSR-18 over Laravel's `Http` facade (fakeable); the service
  provider uses it.
- `Config`: `token_path` / `payments_path` / `refunds_path` from arrays, `retry_on_unauthorized`.
- `ConfigurationException::$key`; `RefundOptions::body()` builds a refund body without a client.
- `BankRejected` and the token-rejection message name the request (`[POST https://…]`).
- `WebhookParser::verify()`: signature of any `payload` value, compared exactly (no trimming);
  `parse()` still requires the payload object.
- Namespace `LearningMachine\KapitalBank\Checkout`, package `learning-machine/kapitalbank-checkout`, MIT.

### 0.1.0

- First release: token, create payment, payment status, refunds, webhook parser, Laravel provider.

## License

MIT, see `LICENSE`.
