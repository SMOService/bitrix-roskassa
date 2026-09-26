<?php

namespace Smoservice\Roskassa\Tests;

use PHPUnit\Framework\TestCase;
use Smoservice\Roskassa\Config;

final class ConfigTest extends TestCase
{
    private static function make(array $values): Config
    {
        $values += ['ROSKASSA_SHOP_ID' => 'SHOP', 'ROSKASSA_SECRET_KEY' => 'secret', 'ROSKASSA_API_KEY' => 'key'];

        return Config::fromReader(static function (string $code) use ($values) {
            return $values[$code] ?? null;
        });
    }

    public function testDefaults(): void
    {
        $cfg = self::make([]);

        self::assertSame('https://roskassa.net', $cfg->baseUrl);
        self::assertFalse($cfg->testMode);
        self::assertSame('bx-42', $cfg->gatewayOrderId(42));
        self::assertNotNull($cfg->apiClient());
    }

    public function testCustomPrefixAndTestMode(): void
    {
        $cfg = self::make(['ROSKASSA_ORDER_PREFIX' => 'smo2', 'ROSKASSA_TEST_MODE' => 'Y', 'ROSKASSA_API_KEY' => 'k']);

        self::assertSame('smo2-7', $cfg->gatewayOrderId(7));
        self::assertTrue($cfg->testMode);
        self::assertNotNull($cfg->apiClient());
    }

    public function testSiteDefaultPrefix(): void
    {
        $cfg = Config::fromReader(static function (string $code) {
            return ['ROSKASSA_SHOP_ID' => 'S', 'ROSKASSA_SECRET_KEY' => 'x', 'ROSKASSA_API_KEY' => 'k'][$code] ?? null;
        }, 'r1a2b3c4d');

        self::assertSame('r1a2b3c4d-5', $cfg->gatewayOrderId(5));
    }

    public function testOwnPaymentIdRequiresOwnPrefix(): void
    {
        $cfg = self::make(['ROSKASSA_ORDER_PREFIX' => 'smo']);

        self::assertSame(15, $cfg->ownPaymentId('smo-15'));
        self::assertNull($cfg->ownPaymentId('bx-15'));
        self::assertNull($cfg->ownPaymentId('15'));
        self::assertNull($cfg->ownPaymentId('smo-015'));
    }

    public function testParsePaymentId(): void
    {
        self::assertSame(718300, Config::parsePaymentId('bx-718300'));
        self::assertSame(1, Config::parsePaymentId('smo2-1'));
        self::assertNull(Config::parsePaymentId('718300'));
        self::assertNull(Config::parsePaymentId('bx-0'));
        self::assertNull(Config::parsePaymentId('bx-12a'));
        self::assertNull(Config::parsePaymentId('b x-1'));
        self::assertNull(Config::parsePaymentId(''));
    }

    public function testFingerprintSeparatesShops(): void
    {
        self::assertSame(
            self::make(['ROSKASSA_API_KEY' => 'k'])->apiFingerprint(),
            self::make(['ROSKASSA_API_KEY' => 'k', 'ROSKASSA_PAYMENT_SYSTEM' => '11'])->apiFingerprint()
        );
        self::assertNotSame(
            self::make(['ROSKASSA_API_KEY' => 'k'])->apiFingerprint(),
            self::make(['ROSKASSA_API_KEY' => 'k', 'ROSKASSA_SHOP_ID' => 'OTHER'])->apiFingerprint()
        );
    }

    /**
     * @dataProvider invalid
     */
    public function testInvalidConfig(array $values): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::make($values);
    }

    public function invalid(): array
    {
        return [
            'no secret' => [['ROSKASSA_SECRET_KEY' => '']],
            'no shop' => [['ROSKASSA_SHOP_ID' => ' ']],
            'no api key' => [['ROSKASSA_API_KEY' => '']],
            'bad prefix' => [['ROSKASSA_ORDER_PREFIX' => 'bx-1']],
            'bad payment system' => [['ROSKASSA_PAYMENT_SYSTEM' => '11a']],
            'relative success url' => [['ROSKASSA_SUCCESS_URL' => '/ok/']],
            'script url' => [['ROSKASSA_FAIL_URL' => 'javascript:alert(1)']],
            'http gateway' => [['ROSKASSA_BASE_URL' => 'http://roskassa.net']],
        ];
    }
}
