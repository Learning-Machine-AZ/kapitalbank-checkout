<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use LearningMachine\KapitalBank\Checkout\Amount;
use LearningMachine\KapitalBank\Checkout\AmountFormat;
use LearningMachine\KapitalBank\Checkout\CheckoutClient;
use LearningMachine\KapitalBank\Checkout\CheckoutResponse;
use LearningMachine\KapitalBank\Checkout\Config;
use LearningMachine\KapitalBank\Checkout\Exception\AuthenticationFailed;
use LearningMachine\KapitalBank\Checkout\Exception\BankRejected;
use LearningMachine\KapitalBank\Checkout\Exception\ConfigurationException;
use LearningMachine\KapitalBank\Checkout\Exception\OutcomeUnknown;
use LearningMachine\KapitalBank\Checkout\Exception\Unreachable;
use LearningMachine\KapitalBank\Checkout\RefundOptions;
use LearningMachine\KapitalBank\Checkout\Status;

final class CheckoutClientTest extends TestCase
{
    private const PAYMENT_ID = '0b6a1f8e-3c2d-4e5f-9a7b-1c2d3e4f5a6b';

    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function client(array $overrides = [], ?ArrayCache $cache = null): CheckoutClient
    {
        $f = new HttpFactory();

        return CheckoutClient::fromConfig(array_replace([
            'base_url' => 'https://precheckout.example.test/api/',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'merchant_id' => 'M-TEST',
            'terminal_id' => 'T-TEST',
        ], $overrides), $this->http, $f, $f, $cache);
    }

    public function testTokenRequestIsClientCredentialsForm(): void
    {
        $this->http->token('abc');
        $this->assertSame('abc', $this->client()->accessToken());

        $r = $this->http->requests[0];
        $this->assertSame('POST', $r->getMethod());
        $this->assertSame('https://precheckout.example.test/api/oauth2/token', (string) $r->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $r->getHeaderLine('Content-Type'));
        parse_str((string) $r->getBody(), $form);
        $this->assertSame(['scope' => 'email', 'grant_type' => 'client_credentials', 'client_id' => 'test-client', 'client_secret' => 'test-secret'], $form);
    }

    public function testTokenIsReusedAndCached(): void
    {
        $cache = new ArrayCache();
        $this->http->token('abc', 3600)
            ->json(200, ['id' => self::PAYMENT_ID, 'status' => 'pending'])
            ->json(200, ['id' => self::PAYMENT_ID, 'status' => 'succeeded']);
        $client = $this->client([], $cache);
        $client->getPayment(self::PAYMENT_ID);
        $client->getPayment(self::PAYMENT_ID);
        $this->assertCount(3, $this->http->requests, 'one token request for two calls');
        $this->assertSame(['abc'], array_values($cache->data));
        $this->assertSame([3540], array_values($cache->ttls));

        // A new client instance picks the token from the PSR-16 cache.
        $this->http->json(200, ['id' => self::PAYMENT_ID, 'status' => 'pending']);
        $this->client([], $cache)->getPayment(self::PAYMENT_ID);
        $this->assertCount(4, $this->http->requests);
        $this->assertSame('Bearer abc', $this->http->requests[3]->getHeaderLine('Authorization'));
    }

    public function testExpiredTokenIsRefreshedOnceOn401(): void
    {
        $cache = new ArrayCache();
        $this->http->token('old')
            ->json(401, ['code' => 'unauthorized'])
            ->token('new')
            ->json(200, ['id' => self::PAYMENT_ID, 'status' => 'pending']);
        $this->client([], $cache)->getPayment(self::PAYMENT_ID);
        $this->assertSame('Bearer new', $this->http->requests[3]->getHeaderLine('Authorization'));
        $this->assertSame(['new'], array_values($cache->data));
    }

    public function testRejectedCredentials(): void
    {
        $this->http->json(401, ['error' => 'invalid_client']);
        try {
            $this->client()->createPayment(Amount::fromMinor(100), 'x', 'https://shop.test/return');
            $this->fail('expected AuthenticationFailed');
        } catch (AuthenticationFailed $e) {
            $this->assertSame(401, $e->httpStatus);
        }
        $this->assertCount(1, $this->http->requests, 'no payment request after a failed token');
    }

    public function testTokenEndpointUnreachableIsAuthenticationFailed(): void
    {
        $this->http->push(new ConnectException('timed out', new Request('POST', 'x')));
        $this->expectException(AuthenticationFailed::class);
        $this->client()->accessToken();
    }

    public function testCreatePaymentBodyHeadersAndRedirect(): void
    {
        $this->http->token()->json(200, [
            'id' => self::PAYMENT_ID,
            'status' => 'pending',
            'description' => 'Top-up',
            'amount' => ['value' => 12.3, 'currency' => 'AZN'],
            'confirmation' => ['type' => 'REDIRECT', 'confirmUrl' => 'https://pay.example.test/checkout'],
        ]);
        $payment = $this->client()->createPayment(Amount::fromDecimal('12.30'), 'Top-up', 'https://shop.test/return', 'idem-1');

        $r = $this->http->requests[1];
        $this->assertSame('POST', $r->getMethod());
        $this->assertSame('https://precheckout.example.test/api/v1/payments', (string) $r->getUri());
        $this->assertSame('Bearer tkn-1', $r->getHeaderLine('Authorization'));
        $this->assertSame('idem-1', $r->getHeaderLine('X-Idempotency-Key'));
        $this->assertSame('application/json', $r->getHeaderLine('Content-Type'));
        $this->assertSame([
            'amount' => ['value' => 12.3, 'currency' => 'AZN'],
            'capture' => true,
            'description' => 'Top-up',
            'paymentMethodData' => ['type' => 'BIRBANK'],
            'confirmation' => ['type' => 'REDIRECT', 'returnUrl' => 'https://shop.test/return'],
            'posDetail' => ['merchantId' => 'M-TEST', 'terminalId' => 'T-TEST'],
        ], $this->http->bodyOf(1));
        $this->assertStringContainsString('"value":12.3,', (string) $r->getBody());

        $this->assertSame(self::PAYMENT_ID, $payment->id);
        $this->assertSame(Status::Pending, $payment->status);
        $this->assertSame(1230, $payment->amount->minor);
        $this->assertSame('https://pay.example.test/checkout?id=' . self::PAYMENT_ID . '&password=idem-1', $payment->redirectUrl());
    }

    public function testCreatePaymentGeneratesUuidKey(): void
    {
        $this->http->token()->json(200, ['id' => 'p1', 'status' => 'pending']);
        $payment = $this->client()->createPayment(Amount::fromMinor(500), 'd', 'https://shop.test/r');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $payment->idempotencyKey);
        $this->assertSame($payment->idempotencyKey, $this->http->requests[1]->getHeaderLine('X-Idempotency-Key'));
        $this->assertSame('{"value":5,"currency":"AZN"}', json_encode($this->http->bodyOf(1)['amount']));
    }

    public function testGetPaymentParsesStatusAndRrn(): void
    {
        $this->http->token()->json(200, [
            'id' => self::PAYMENT_ID,
            'status' => 'SUCCEEDED',
            'amount' => ['value' => '3.00', 'currency' => 'AZN'],
            'authorizationDetail' => ['rrn' => '123456789012'],
        ]);
        $p = $this->client()->getPayment(self::PAYMENT_ID);
        $this->assertSame('GET', $this->http->requests[1]->getMethod());
        $this->assertSame('https://precheckout.example.test/api/v1/payments/' . self::PAYMENT_ID, (string) $this->http->requests[1]->getUri());
        $this->assertSame(Status::Succeeded, $p->status);
        $this->assertSame('123456789012', $p->rrn);
        $this->assertSame(300, $p->amount->minor);
        $this->assertNull($p->redirectUrl());
    }

    public function testRefundSendsProvenBody(): void
    {
        $this->http->token()->json(200, [
            'id' => 'r-1',
            'originalId' => self::PAYMENT_ID,
            'status' => 'succeeded',
            'amount' => ['value' => 2.5, 'currency' => 'AZN'],
            'rrn' => '628000000001',
        ]);
        $refund = $this->client()->refund(self::PAYMENT_ID, Amount::fromDecimal('2.50'), 'refund-key-1');

        $r = $this->http->requests[1];
        $this->assertSame('https://precheckout.example.test/api/v1/refunds', (string) $r->getUri());
        $this->assertSame('refund-key-1', $r->getHeaderLine('X-Idempotency-Key'));
        $this->assertSame('{"id":"' . self::PAYMENT_ID . '","amount":2.5}', (string) $r->getBody());
        $this->assertSame(Status::Succeeded, $refund->status);
        $this->assertSame('628000000001', $refund->rrn);
        $this->assertSame('r-1', $refund->id);
        $this->assertSame(self::PAYMENT_ID, $refund->paymentId);
        $this->assertSame(250, $refund->amount->minor);
        $this->assertSame(['id' => self::PAYMENT_ID, 'amount' => 2.5], $refund->requestBody);
    }

    public static function refundVariants(): iterable
    {
        $id = self::PAYMENT_ID;
        yield 'string' => [['amount_format' => 'string'], '{"id":"' . $id . '","amount":"2.50"}'];
        yield 'object' => [['amount_format' => 'object'], '{"id":"' . $id . '","amount":{"value":2.5,"currency":"AZN"}}'];
        yield 'object_string + originalId' => [
            ['amount_format' => 'object_string', 'id_field' => 'originalId'],
            '{"originalId":"' . $id . '","amount":{"value":"2.50","currency":"AZN"}}',
        ];
        yield 'pos + description + extra' => [
            ['send_pos_detail' => true, 'send_description' => true, 'extra_json' => '{"currency":"AZN"}'],
            '{"id":"' . $id . '","amount":2.5,"description":"why","posDetail":{"merchantId":"M-TEST","terminalId":"T-TEST"},"currency":"AZN"}',
        ];
    }

    #[DataProvider('refundVariants')]
    public function testRefundBodyVariantsFromConfig(array $refundConfig, string $expected): void
    {
        $client = $this->client(['refund' => $refundConfig]);
        $body = $client->refundBody(self::PAYMENT_ID, Amount::fromMinor(250), null, 'why');
        $this->assertSame($expected, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    public function testRefundOptionsPerCallOverrideConfig(): void
    {
        $body = $this->client()->refundBody('p', Amount::fromMinor(1000), new RefundOptions(idField: 'paymentId', amountFormat: AmountFormat::Object));
        $this->assertSame('{"paymentId":"p","amount":{"value":10,"currency":"AZN"}}', json_encode($body));
    }

    public function testInvalidRefundExtraJsonFailsBeforeSending(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client(['refund' => ['extra_json' => '[1,2]']]);
    }

    public function testInvalidAmountFormatFailsBeforeSending(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client(['refund' => ['amount_format' => 'cents']]);
    }

    public function testBankErrorIsMapped(): void
    {
        $this->http->token()->json(400, [
            'id' => 'e1e2e3e4-0000-4000-8000-000000000000',
            'code' => 'bad_request',
            'status' => 400,
            'method' => 'POST',
            'path' => '/api/v1/refunds',
            'message' => 'Malformed JSON request',
            'timestamp' => '2026-10-07T10:00:00Z',
            'errors' => [['property' => 'amount', 'message' => 'must be positive']],
        ]);
        try {
            $this->client()->refund(self::PAYMENT_ID, Amount::fromMinor(250), 'k');
            $this->fail('expected BankRejected');
        } catch (BankRejected $e) {
            $this->assertSame(400, $e->error->httpStatus);
            $this->assertSame('bad_request', $e->error->code);
            $this->assertSame('Malformed JSON request', $e->error->message);
            $this->assertSame('e1e2e3e4-0000-4000-8000-000000000000', $e->error->traceId);
            $this->assertSame('POST', $e->error->method);
            $this->assertSame('/api/v1/refunds', $e->error->path);
            $this->assertSame('2026-10-07T10:00:00Z', $e->error->timestamp);
            $this->assertSame([['property' => 'amount', 'message' => 'must be positive']], $e->error->errors);
            $this->assertFalse($e->isServerError());
        }
    }

    public function testServerErrorWithHtmlBody(): void
    {
        $this->http->token()->push(new Response(502, ['Content-Type' => 'text/html'], '<html><body>Bad Gateway</body></html>'));
        try {
            $this->client()->getPayment('p');
            $this->fail('expected BankRejected');
        } catch (BankRejected $e) {
            $this->assertTrue($e->isServerError());
            $this->assertSame('Bad Gateway', $e->error->message);
        }
    }

    public function testTimeoutIsOutcomeUnknown(): void
    {
        $this->http->token()->push(new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'x')));
        try {
            $this->client()->refund(self::PAYMENT_ID, Amount::fromMinor(250), 'k');
            $this->fail('expected Unreachable');
        } catch (Unreachable $e) {
            $this->assertInstanceOf(OutcomeUnknown::class, $e);
        }
    }

    public function testNonJsonSuccessIsOutcomeUnknown(): void
    {
        $this->http->token()->push(new Response(200, [], 'OK'));
        $this->expectException(OutcomeUnknown::class);
        $this->client()->refund(self::PAYMENT_ID, Amount::fromMinor(250), 'k');
    }

    public function testConfigDefaultsAndValidation(): void
    {
        $c = Config::fromArray(['client_id' => 'a', 'client_secret' => 'b', 'environment' => 'preprod']);
        $this->assertSame('https://precheckout.kapitalbank.az/api', $c->baseUrl);
        $this->assertSame('https://checkout.kapitalbank.az/api', Config::fromArray(['client_id' => 'a', 'client_secret' => 'b'])->baseUrl);

        $this->expectException(ConfigurationException::class);
        Config::fromArray(['client_id' => 'a', 'client_secret' => '']);
    }

    public function testResultsCarryTheAnswer(): void
    {
        $this->http->token()
            ->json(201, ['id' => 'r-1', 'status' => 'succeeded', 'rrn' => '628000000001'])
            ->json(200, ['id' => self::PAYMENT_ID, 'status' => 'succeeded', 'amount' => ['value' => 3, 'currency' => 'AZN']]);
        $client = $this->client();

        $refund = $client->refund(self::PAYMENT_ID, Amount::fromMinor(250), 'k');
        $this->assertInstanceOf(CheckoutResponse::class, $refund->response);
        $this->assertSame(201, $refund->response->httpStatus);
        $this->assertSame('succeeded', $refund->response->status());
        $this->assertSame('628000000001', $refund->response->rrn());

        $payment = $client->getPayment(self::PAYMENT_ID);
        $this->assertSame(200, $payment->response->httpStatus);
        $this->assertSame(300, $payment->response->amountMinor());
        $this->assertCount(3, $this->http->requests, 'one token for both calls');
    }

    public function testBankRejectedNamesTheRequestAndKeepsTheAnswer(): void
    {
        $this->http->token()->json(502, ['message' => 'Bad gateway', 'accessToken' => 'leak']);
        try {
            $this->client()->createPayment(Amount::fromMinor(500), 'd', 'https://shop.test/r', 'k');
            $this->fail('expected BankRejected');
        } catch (BankRejected $e) {
            $this->assertSame('POST https://precheckout.example.test/api/v1/payments', $e->request);
            $this->assertStringEndsWith('[POST https://precheckout.example.test/api/v1/payments]', $e->getMessage());
            $this->assertSame(502, $e->error->response->httpStatus);
            $this->assertTrue($e->error->response->serverError());
            $this->assertStringContainsString('"accessToken": "[redacted]"', (string) $e->error->response->forStorage());
        }
    }

    public function testRejectedTokenMessageNamesTheTokenEndpoint(): void
    {
        $this->http->json(401, ['error' => 'invalid_client']);
        $this->expectExceptionMessage('The bank rejected the merchant authentication (HTTP 401) [POST https://precheckout.example.test/api/oauth2/token].');
        $this->client()->accessToken();
    }

    public function testUnusableSuccessAnswersAreKeptOnOutcomeUnknown(): void
    {
        $this->http->token()->push(new Response(200, [], 'OK'))->json(200, ['status' => 'succeeded']);
        $client = $this->client();
        try {
            $client->refund(self::PAYMENT_ID, Amount::fromMinor(250), 'k');
            $this->fail('expected OutcomeUnknown');
        } catch (OutcomeUnknown $e) {
            $this->assertNotInstanceOf(Unreachable::class, $e);
            $this->assertSame(200, $e->response->httpStatus);
            $this->assertSame([], $e->response->body);
            $this->assertSame('OK', $e->response->rawBody);
        }
        try {
            $client->getPayment(self::PAYMENT_ID);
            $this->fail('expected OutcomeUnknown');
        } catch (OutcomeUnknown $e) {
            $this->assertSame('succeeded', $e->response->status());
        }
    }

    public function testUnreachableHasNoAnswer(): void
    {
        $this->http->token()->push(new ConnectException('cURL error 28: Operation timed out', new Request('GET', 'x')));
        try {
            $this->client()->getPayment('p');
            $this->fail('expected Unreachable');
        } catch (Unreachable $e) {
            $this->assertNull($e->response);
        }
    }

    public function testRetryOn401CanBeSwitchedOff(): void
    {
        $this->http->token('old')->json(401, ['code' => 'unauthorized']);
        try {
            $this->client(['retry_on_unauthorized' => false])->refund(self::PAYMENT_ID, Amount::fromMinor(250), 'k');
            $this->fail('expected BankRejected');
        } catch (BankRejected $e) {
            $this->assertSame(401, $e->error->httpStatus);
        }
        $this->assertCount(2, $this->http->requests, 'no second token or refund request');
    }

    public function testPathsFromConfig(): void
    {
        $c = Config::fromArray(['client_id' => 'a', 'client_secret' => 'b', 'token_path' => '/auth/token', 'payments_path' => '/v2/payments', 'refunds_path' => ' ']);
        $this->assertSame('/auth/token', $c->tokenPath);
        $this->assertSame('/v2/payments', $c->paymentsPath);
        $this->assertSame('/v1/refunds', $c->refundsPath);
        $this->assertTrue($c->retryOnUnauthorized);
    }

    public static function badSettings(): iterable
    {
        yield 'client_id' => [fn () => Config::fromArray(['client_id' => '', 'client_secret' => 'b']), 'client_id'];
        yield 'base_url' => [fn () => Config::fromArray(['client_id' => 'a', 'client_secret' => 'b', 'base_url' => 'nope']), 'base_url'];
        yield 'amount_format' => [fn () => RefundOptions::fromArray(['amount_format' => 'cents']), 'refund.amount_format'];
        yield 'extra_json' => [fn () => RefundOptions::fromArray(['extra_json' => '{oops']), 'refund.extra_json'];
        yield 'extra_json list' => [fn () => RefundOptions::fromArray(['extra_json' => '["a"]']), 'refund.extra_json'];
    }

    #[DataProvider('badSettings')]
    public function testConfigurationErrorsNameTheSetting(\Closure $build, string $key): void
    {
        try {
            $build();
            $this->fail('expected ConfigurationException');
        } catch (ConfigurationException $e) {
            $this->assertSame($key, $e->key);
        }
    }

    public function testRefundBodyWithoutAClient(): void
    {
        $o = RefundOptions::fromArray(['original_id_field' => 'originalId', 'send_pos_detail' => true, 'send_description' => true]);
        $this->assertSame(
            ['originalId' => 'p', 'amount' => 3.0, 'description' => 'why', 'posDetail' => ['merchantId' => 'M', 'terminalId' => 'T']],
            $o->body('p', Amount::fromMinor(300), 'M', 'T', 'why')
        );
        $this->assertSame('{"id":"p","amount":3}', json_encode((new RefundOptions())->body('p', Amount::fromMinor(300))));
    }
}
