<?php

namespace Smoservice\Roskassa\Tests;

use PHPUnit\Framework\TestCase;
use Smoservice\Roskassa\NotifyValidator;

final class NotifyValidatorTest extends TestCase
{
    private const SECRET = 's3cret';
    private const SHOP = 'D0F98E7D7742609DC508D86BB7500914';

    private function check(array $overrides, bool $testMode = false, string $amount = '100.00', string $currency = 'RUB'): ?string
    {
        return NotifyValidator::validate(
            GatewayReference::notification($overrides, self::SECRET),
            self::SECRET,
            self::SHOP,
            'bx-718300',
            $amount,
            $currency,
            $testMode
        );
    }

    public function testValidNotification(): void
    {
        self::assertNull($this->check([]));
    }

    public function testShopMismatch(): void
    {
        self::assertSame('shop_mismatch', $this->check(['shop_id' => 'OTHER']));
    }

    public function testUnpaidStatus(): void
    {
        self::assertSame('not_paid', $this->check(['status' => 0]));
    }

    public function testTestPaymentRejectedInLiveMode(): void
    {
        self::assertSame('test_payment_in_live_mode', $this->check(['test' => 1]));
        self::assertNull($this->check(['test' => 1], true));
    }

    public function testAmountMismatch(): void
    {
        self::assertSame('amount_mismatch', $this->check(['amount' => 99]));
        self::assertSame('amount_mismatch', $this->check(['amount' => 0], false, '0.00'));
    }

    public function testCurrencyMismatch(): void
    {
        self::assertSame('currency_mismatch', $this->check(['currency' => 'USD']));
        self::assertNull($this->check(['currency' => 'usd'], false, '100.00', 'USD'));
    }

    public function testOrderNumberOfAnotherPaymentOrIntegration(): void
    {
        self::assertSame('order_mismatch', $this->check(['order_id' => 'bx-718301']));
        self::assertSame('order_mismatch', $this->check(['order_id' => '718300']));
    }

    public function testMissingGatewayId(): void
    {
        self::assertSame('missing_field:id', $this->check(['id' => null]));
    }

    public function testMissingField(): void
    {
        self::assertSame('missing_field:currency', $this->check(['currency' => null]));
    }

    public function testForgedNotificationWithoutSecret(): void
    {
        $forged = GatewayReference::notification([], 'guessed-secret');

        self::assertSame('bad_signature', NotifyValidator::validate($forged, self::SECRET, self::SHOP, 'bx-718300', '100.00', 'RUB', false));
    }

    public function testEmptyShopIdInConfigFailsClosed(): void
    {
        $data = GatewayReference::notification([], self::SECRET);

        self::assertSame('shop_mismatch', NotifyValidator::validate($data, self::SECRET, '', 'bx-718300', '100.00', 'RUB', false));
    }
}
