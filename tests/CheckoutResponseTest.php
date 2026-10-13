<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Tests;

use LearningMachine\KapitalBank\Checkout\CheckoutResponse;
use PHPUnit\Framework\TestCase;

final class CheckoutResponseTest extends TestCase
{
    public function testSuccessObject(): void
    {
        $r = new CheckoutResponse(201, [
            'id' => 'r-1', 'status' => 'SUCCEEDED', 'rrn' => '628000000001',
            'amount' => ['value' => 2.4, 'currency' => 'azn'], 'cancellationReason' => 'x',
        ]);
        $this->assertTrue($r->successful());
        $this->assertSame('succeeded', $r->status());
        $this->assertSame('r-1', $r->id());
        $this->assertNull($r->errorId());
        $this->assertSame('628000000001', $r->rrn());
        $this->assertSame(240, $r->amountMinor());
        $this->assertSame('AZN', $r->currency());
        $this->assertSame('x', $r->errorCode());
    }

    public function testErrorAnswerIsReadableScrubbedAndCapped(): void
    {
        $r = new CheckoutResponse(400, [
            'id' => '5f79e26b-ae78-4599-a688-e800b30be7d3',
            'code' => 'bad_request',
            'status' => 400,
            'message' => 'Malformed JSON request',
            'debugMessage' => 'card 4169 7388 8650 1026 ' . str_repeat('x', 300),
            'errors' => [['property' => 'amount', 'message' => 'must be a number']],
        ]);
        $this->assertNull($r->status());
        $this->assertNull($r->id());
        $this->assertSame('5f79e26b-ae78-4599-a688-e800b30be7d3', $r->errorId());
        $this->assertSame('bad_request', $r->errorCode());
        $msg = (string) $r->errorMessage();
        $this->assertStringStartsWith('Malformed JSON request — card [masked] xxx', $msg);
        $this->assertStringEndsWith('[bank error id 5f79e26b-ae78-4599-a688-e800b30be7d3]', $msg);
        $this->assertLessThanOrEqual(255, mb_strlen($msg));
    }

    public function testValidationErrorsAreListed(): void
    {
        $r = new CheckoutResponse(422, ['message' => 'Invalid', 'errors' => [['field' => 'amount', 'defaultMessage' => 'too big'], 'plain']]);
        $this->assertSame('Invalid — amount: too big — plain', $r->errorMessage());
    }

    public function testNonJsonAnswer(): void
    {
        $r = CheckoutResponse::fromRaw(400, '<html><body><h1>400 Bad Request</h1></body></html>');
        $this->assertSame([], $r->body);
        $this->assertSame('400 Bad Request', $r->errorMessage());
        $this->assertStringContainsString('<h1>400 Bad Request</h1>', (string) $r->forStorage());
        $this->assertNull(CheckoutResponse::fromRaw(200, '')->forStorage());
        $this->assertNull(CheckoutResponse::fromRaw(200, 'OK')->errorMessage(), 'raw text is an error message only on errors');
    }

    public function testStorageRedactsSecretsAndMasksCards(): void
    {
        $r = new CheckoutResponse(200, [
            'accessToken' => 'secret-value',
            'card' => ['pan' => '4169738886501026', 'note' => 'card 4169-7388-8650-1026'],
            'id' => '42343041-3525-4e64-b12c-a0eea0e43858',
        ]);
        $stored = json_decode((string) $r->forStorage(), true);
        $this->assertSame('[redacted]', $stored['accessToken']);
        $this->assertSame('[redacted]', $stored['card']['pan']);
        $this->assertSame('card [masked]', $stored['card']['note']);
        $this->assertSame('42343041-3525-4e64-b12c-a0eea0e43858', $stored['id']);

        $this->assertSame(['client_secret' => '[redacted]', 'a' => 1], json_decode(CheckoutResponse::encodeForStorage(['client_secret' => 's', 'a' => 1]), true));
        $this->assertLessThanOrEqual(CheckoutResponse::STORAGE_LIMIT, mb_strlen(CheckoutResponse::encodeForStorage(['x' => str_repeat('y', 9000)])));
    }

    public function testScrubLeavesUuidsAlone(): void
    {
        $this->assertSame('order 42343041-3525-4e64-b12c-a0eea0e43858', CheckoutResponse::scrub('order 42343041-3525-4e64-b12c-a0eea0e43858'));
        $this->assertSame('pan [masked] x', CheckoutResponse::scrub('pan 4169-7388-8650-1026 x'));
    }
}
