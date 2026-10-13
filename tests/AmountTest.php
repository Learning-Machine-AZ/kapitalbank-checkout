<?php

declare(strict_types=1);

namespace LearningMachine\KapitalBank\Checkout\Tests;

use PHPUnit\Framework\TestCase;
use LearningMachine\KapitalBank\Checkout\Amount;
use LearningMachine\KapitalBank\Checkout\Status;

final class AmountTest extends TestCase
{
    public function testDecimalParsingHasNoFloatDrift(): void
    {
        $this->assertSame(1999, Amount::fromDecimal('19.99')->minor);
        $this->assertSame(250, Amount::fromDecimal('2.5')->minor);
        $this->assertSame(300, Amount::fromDecimal(3)->minor);
        $this->assertSame('0.07', Amount::fromMinor(7)->toDecimalString());
        $this->assertSame(0.29, Amount::fromDecimal('0.29')->toJsonNumber());
        $this->assertSame(29, Amount::fromBank(0.29)->minor);
        $this->assertSame(1999, Amount::fromBank('19.99')->minor);
        $this->assertSame('2.50 AZN', (string) Amount::fromMinor(250));
    }

    public function testRejectsBadInput(): void
    {
        foreach (['2.505', '-1', 'abc', '1,50', ''] as $bad) {
            try {
                Amount::fromDecimal($bad);
                $this->fail("accepted {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBankAmountsNeverThrow(): void
    {
        $this->assertSame(250, Amount::fromBank(2.5)->minor);
        $this->assertSame(300, Amount::fromBank('3', 'azn')->minor);
        $this->assertSame(1, Amount::fromBank('0.005')->minor);
        $this->assertNull(Amount::fromBank(-1));
        $this->assertNull(Amount::fromBank('-2.50'));
        $this->assertNull(Amount::fromBank(5, ''));
        $this->assertNull(Amount::fromBank(5, 'MANAT'));
        $this->assertNull(Amount::fromBank(['value' => 1]));
        $this->assertNull(Amount::fromBank(null));
    }

    public function testStatusMapping(): void
    {
        $this->assertSame(Status::Succeeded, Status::fromBank('Succeeded'));
        $this->assertSame(Status::Canceled, Status::fromBank('cancelled'));
        $this->assertSame(Status::Pending, Status::fromBank('pending'));
        $this->assertSame(Status::Unknown, Status::fromBank('declined'));
        $this->assertSame(Status::Unknown, Status::fromBank(null));
        $this->assertTrue(Status::Canceled->isFinal());
        $this->assertFalse(Status::Pending->isFinal());
    }
}
