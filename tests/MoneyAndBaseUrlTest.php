<?php

namespace Smoservice\Roskassa\Tests;

use PHPUnit\Framework\TestCase;
use Smoservice\Roskassa\BaseUrl;
use Smoservice\Roskassa\Money;

final class MoneyAndBaseUrlTest extends TestCase
{
    public function testFormat(): void
    {
        self::assertSame('100.00', Money::format(100));
        self::assertSame('100.00', Money::format('99.999'));
        self::assertSame('0.10', Money::format(0.1));
        self::assertSame('1234567.89', Money::format('1234567.89'));
    }

    public function testEqualsIgnoresRepresentation(): void
    {
        self::assertTrue(Money::equals('100', '100.00'));
        self::assertTrue(Money::equals(0.1 + 0.2, '0.30'));
        self::assertFalse(Money::equals('100', '100.01'));
        self::assertFalse(Money::equals('abc', '0'));
        self::assertFalse(Money::equals(null, '0'));
    }

    public function testToMinorRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toMinor('100rub');
    }

    public function testBaseUrlDefaultAndNormalization(): void
    {
        self::assertSame('https://roskassa.net', BaseUrl::normalize(''));
        self::assertSame('https://roskassa.net', BaseUrl::normalize(' https://Roskassa.net/ '));
        self::assertSame('https://pay.example.com:8443', BaseUrl::normalize('https://pay.example.com:8443'));
    }

    /**
     * @dataProvider badUrls
     */
    public function testBaseUrlRejectsUnsafe(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BaseUrl::normalize($url);
    }

    public function badUrls(): array
    {
        return [
            'plain http' => ['http://roskassa.net'],
            'with path' => ['https://roskassa.net/pay'],
            'no tld' => ['https://localhost'],
            'credentials' => ['https://user:pass@roskassa.net'],
            'javascript' => ['javascript:alert(1)'],
        ];
    }
}
