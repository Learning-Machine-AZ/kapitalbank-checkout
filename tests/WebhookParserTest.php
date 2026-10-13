<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Tests;

use PHPUnit\Framework\TestCase;
use LearningMachine\KapitalBank\Checkout\Exception\InvalidWebhook;
use LearningMachine\KapitalBank\Checkout\Status;
use LearningMachine\KapitalBank\Checkout\Webhook\WebhookParser;

final class WebhookParserTest extends TestCase
{
    private function body(array $payload): string
    {
        return json_encode(['payload' => $payload, 'sentAt' => '2026-10-07T10:00:00Z']);
    }

    public function testValidSignedSucceeded(): void
    {
        $parser = new WebhookParser('whsec-test');
        $payload = ['id' => 'pay-1', 'type' => 'payment', 'paymentMethod' => 'BIRBANK', 'status' => 'succeeded'];
        $sig = base64_encode(hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'whsec-test', true));

        $event = $parser->parse($this->body($payload), $sig);
        $this->assertSame('pay-1', $event->id);
        $this->assertSame(Status::Succeeded, $event->status);
        $this->assertTrue($event->isSucceeded());
        $this->assertSame('BIRBANK', $event->paymentMethod);
    }

    public function testCanceledIsParsedNotRejected(): void
    {
        $parser = new WebhookParser('s');
        $payload = ['id' => 'pay-2', 'type' => 'payment', 'status' => 'canceled'];
        $event = $parser->parse($this->body($payload), $parser->sign($payload));
        $this->assertSame(Status::Canceled, $event->status);
        $this->assertFalse($event->isSucceeded());
    }

    public function testBadSignatureRejected(): void
    {
        $parser = new WebhookParser('s');
        $payload = ['id' => 'pay-1', 'type' => 'payment', 'status' => 'succeeded'];
        $this->assertFalse($parser->verify($this->body($payload), 'nope'));
        $this->assertFalse($parser->verify($this->body($payload), null));
        $this->expectException(InvalidWebhook::class);
        $parser->parse($this->body($payload), (new WebhookParser('other'))->sign($payload));
    }

    public function testMissingFieldsRejected(): void
    {
        $parser = new WebhookParser('s');
        $payload = ['id' => 'pay-1', 'type' => 'payment'];
        $this->expectException(InvalidWebhook::class);
        $parser->parse($this->body($payload), $parser->sign($payload));
    }

    public function testNotJsonRejected(): void
    {
        $this->expectException(InvalidWebhook::class);
        WebhookParser::parseUnverified('<xml/>');
    }

    public function testSignatureIsComparedExactly(): void
    {
        $parser = new WebhookParser('s');
        $payload = ['id' => 'pay-1', 'type' => 'payment', 'status' => 'succeeded'];
        $sig = $parser->sign($payload);
        $this->assertTrue($parser->verify($this->body($payload), $sig));
        $this->assertFalse($parser->verify($this->body($payload), ' ' . $sig . ' '), 'no trimming');
        $this->assertFalse($parser->verify(json_encode(['data' => $payload]), $sig), 'no payload key');
        $this->assertFalse($parser->verify('123', $sig), 'not a JSON object');
    }

    public function testAnyPayloadValueIsSigned(): void
    {
        // verify() only proves who sent the body; the structure is parse()'s job.
        $parser = new WebhookParser('s');
        $this->assertTrue($parser->verify('{"payload":"text"}', $parser->sign('text')));
        $this->assertTrue($parser->verify('{"payload":null}', $parser->sign(null)));
        $this->expectException(InvalidWebhook::class);
        $parser->parse('{"payload":"text"}', $parser->sign('text'));
    }

    public function testSlashesAndUnicodeAreSignedUnescaped(): void
    {
        $parser = new WebhookParser('s');
        $payload = ['id' => 'a/b', 'type' => 'ödəniş', 'status' => 'succeeded'];
        $expected = base64_encode(hash_hmac('sha256', '{"id":"a/b","type":"ödəniş","status":"succeeded"}', 's', true));
        $this->assertSame($expected, $parser->sign($payload));
    }
}
