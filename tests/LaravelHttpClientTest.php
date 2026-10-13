<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use LearningMachine\KapitalBank\Checkout\Amount;
use LearningMachine\KapitalBank\Checkout\CheckoutClient;
use LearningMachine\KapitalBank\Checkout\Exception\AuthenticationFailed;
use LearningMachine\KapitalBank\Checkout\Exception\Unreachable;
use LearningMachine\KapitalBank\Checkout\Laravel\LaravelHttpClient;
use LearningMachine\KapitalBank\Checkout\Laravel\NetworkError;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;

final class LaravelHttpClientTest extends TestCase
{
    private const BASE = 'https://precheckout.example.test/api';

    private Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Factory();
        $this->factory->preventStrayRequests();
    }

    private function checkout(?LaravelHttpClient $http = null): CheckoutClient
    {
        $f = new HttpFactory();

        return CheckoutClient::fromConfig([
            'base_url' => self::BASE,
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'merchant_id' => 'M-TEST',
            'terminal_id' => 'T-TEST',
        ], $http ?? new LaravelHttpClient(30, 10, $this->factory), $f, $f);
    }

    public function testRequestsGoThroughLaravelUnchanged(): void
    {
        $this->factory->fake([
            self::BASE . '/oauth2/token' => Factory::response(['access_token' => 'tkn', 'expires_in' => 300]),
            self::BASE . '/v1/refunds' => Factory::response(['id' => 'r-1', 'status' => 'succeeded'], 201),
        ]);

        $refund = $this->checkout()->refund('pay-1', Amount::fromMinor(240), 'refund-key');

        $this->assertSame(201, $refund->response->httpStatus);
        $sent = $this->factory->recorded()->map(fn ($pair) => $pair[0])->all();
        $this->assertCount(2, $sent);

        /** @var Request $token */
        [$token, $post] = $sent;
        $this->assertSame('POST', $token->method());
        $this->assertSame(self::BASE . '/oauth2/token', $token->url());
        $this->assertSame(['application/x-www-form-urlencoded'], $token->header('Content-Type'));
        $this->assertSame(['scope' => 'email', 'grant_type' => 'client_credentials', 'client_id' => 'test-client', 'client_secret' => 'test-secret'], $token->data());

        $this->assertSame('POST', $post->method());
        $this->assertSame(self::BASE . '/v1/refunds', $post->url());
        $this->assertSame(['Bearer tkn'], $post->header('Authorization'));
        $this->assertSame(['refund-key'], $post->header('X-Idempotency-Key'));
        $this->assertSame(['application/json'], $post->header('Accept'));
        $this->assertSame(['application/json'], $post->header('Content-Type'));
        $this->assertTrue($post->isJson());
        $this->assertSame('{"id":"pay-1","amount":2.4}', $post->body());
    }

    public function testGetHasNoBodyAndErrorsComeBackAsResponses(): void
    {
        $this->factory->fake([
            self::BASE . '/oauth2/token' => Factory::response(['access_token' => 'tkn']),
            self::BASE . '/v1/payments/*' => Factory::response('<html>Bad Gateway</html>', 502, ['Content-Type' => 'text/html']),
        ]);

        try {
            $this->checkout()->getPayment('pay-1');
            $this->fail('expected BankRejected');
        } catch (\LearningMachine\KapitalBank\Checkout\Exception\BankRejected $e) {
            $this->assertSame(502, $e->error->httpStatus);
            $this->assertSame('<html>Bad Gateway</html>', $e->error->response->rawBody);
        }

        $get = $this->factory->recorded()->map(fn ($pair) => $pair[0])->last();
        $this->assertSame('GET', $get->method());
        $this->assertSame('', $get->body());
        $this->assertSame([], $get->header('Content-Type'));
    }

    public function testConnectionFailureIsANetworkException(): void
    {
        $this->factory->fake(function (Request $request) {
            throw new ConnectException('cURL error 28: Operation timed out for ' . $request->url(), new PsrRequest('POST', $request->url()));
        });

        $client = new LaravelHttpClient(5, 2, $this->factory);
        $request = new PsrRequest('POST', self::BASE . '/oauth2/token');
        try {
            $client->sendRequest($request);
            $this->fail('expected NetworkError');
        } catch (NetworkError $e) {
            $this->assertInstanceOf(NetworkExceptionInterface::class, $e);
            $this->assertSame($request, $e->getRequest());
            $this->assertStringContainsString('/oauth2/token', $e->getMessage());
        }

        try {
            $this->checkout($client)->accessToken();
            $this->fail('expected AuthenticationFailed');
        } catch (AuthenticationFailed $e) {
            $this->assertNull($e->httpStatus);
            $this->assertStringContainsString('/oauth2/token', $e->getMessage());
        }
    }

    public function testTimeoutOnAnApiCallIsUnreachable(): void
    {
        $this->factory->fake(function (Request $request) {
            if (str_ends_with($request->url(), '/oauth2/token')) {
                return Factory::response(['access_token' => 'tkn']);
            }
            throw new ConnectException('cURL error 28: Operation timed out', new PsrRequest('POST', $request->url()));
        });

        $this->expectException(Unreachable::class);
        $this->checkout()->refund('pay-1', Amount::fromMinor(100), 'k');
    }

    public function testTimeoutsArePassedToLaravel(): void
    {
        $seen = [];
        $this->factory->fake(function (Request $request, array $options) use (&$seen) {
            $seen = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

            return Factory::response(['ok' => true]);
        });

        (new LaravelHttpClient(0, 0, $this->factory))->sendRequest(new PsrRequest('GET', self::BASE . '/x'));
        $this->assertEquals([0, 0], $seen, '0 = no limit');

        (new LaravelHttpClient(12.5, 3, $this->factory))->sendRequest(new PsrRequest('GET', self::BASE . '/x'));
        $this->assertEquals([12.5, 3], $seen);
    }
}
