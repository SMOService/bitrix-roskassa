<?php

namespace Smoservice\Roskassa\Tests;

use PHPUnit\Framework\TestCase;
use Smoservice\Roskassa\ApiClient;
use Smoservice\Roskassa\ApiException;
use Smoservice\Roskassa\OrderVerifier;

final class OrderVerifierTest extends TestCase
{
    private function verifier(array $responses, ?FakeGateway &$gateway = null): OrderVerifier
    {
        $gateway = new FakeGateway($responses);

        return new OrderVerifier(new ApiClient('https://roskassa.net', 'SHOP', 'k', $gateway));
    }

    private static function one(array $order): array
    {
        return ['type' => 'success', 'desc' => '', 'data' => $order];
    }

    private static function many(array $orders): array
    {
        return ['type' => 'success', 'desc' => '', 'data' => $orders];
    }

    public function testPaidLatestAttempt(): void
    {
        $verdict = $this->verifier(['api/order' => self::one(FakeGateway::order())], $gateway)
            ->verify('bx-718300', '100.00', 'RUB', false, 'a1b2c3d4e5f6');

        self::assertSame(OrderVerifier::PAID, $verdict['status']);
        self::assertCount(1, $gateway->requests, 'paid latest attempt needs no list scan');
    }

    public function testEarlierAttemptPaidLatestUnpaid(): void
    {
        $verdict = $this->verifier([
            'api/order' => self::one(FakeGateway::order(['id' => 'latest', 'status' => 0, 'date_payed' => null])),
            'api/orders' => self::many([
                FakeGateway::order(['id' => 'someone-else', 'order_id' => 'bx-1']),
                FakeGateway::order(['id' => 'first-attempt']),
            ]),
        ])->verify('bx-718300', '100.00', 'RUB', false);

        self::assertSame(OrderVerifier::PAID, $verdict['status']);
        self::assertSame('first-attempt', $verdict['order']['id']);
    }

    public function testGatewayIdFromNotificationMustMatch(): void
    {
        $verdict = $this->verifier([
            'api/order' => self::one(FakeGateway::order(['id' => 'other'])),
            'api/orders' => self::many([FakeGateway::order(['id' => 'other'])]),
        ])->verify('bx-718300', '100.00', 'RUB', false, 'from-notification');

        self::assertSame(OrderVerifier::UNPAID, $verdict['status']);
    }

    public function testAmountMismatch(): void
    {
        $bad = FakeGateway::order(['amount' => 1]);
        $verdict = $this->verifier(['api/order' => self::one($bad), 'api/orders' => self::many([$bad])])
            ->verify('bx-718300', '100.00', 'RUB', false);

        self::assertSame(OrderVerifier::MISMATCH, $verdict['status']);
        self::assertSame('amount', $verdict['reason']);
    }

    public function testCurrencyMissingOrDifferent(): void
    {
        $missing = FakeGateway::order();
        unset($missing['currency']);

        $usd = FakeGateway::order(['currency' => 'USD']);

        self::assertSame('currency', $this->verifier(['api/order' => self::one($missing), 'api/orders' => self::many([$missing])])
            ->verify('bx-718300', '100.00', 'RUB', false)['reason']);
        self::assertSame('currency', $this->verifier(['api/order' => self::one($usd), 'api/orders' => self::many([$usd])])
            ->verify('bx-718300', '100.00', 'RUB', false)['reason']);
    }

    public function testTestOrderOnlyInTestMode(): void
    {
        $test = FakeGateway::order(['test_order' => 1]);
        $responses = ['api/order' => self::one($test), 'api/orders' => self::many([$test])];

        self::assertSame(OrderVerifier::MISMATCH, $this->verifier($responses)->verify('bx-718300', '100.00', 'RUB', false)['status']);
        self::assertSame(OrderVerifier::PAID, $this->verifier($responses)->verify('bx-718300', '100.00', 'RUB', true)['status']);
    }

    public function testUnknownPayment(): void
    {
        $verdict = $this->verifier([
            'api/order' => ['type' => 'error', 'desc' => 'Order not found', 'data' => []],
            'api/orders' => ['type' => 'error', 'desc' => 'Order not found', 'data' => []],
        ])->verify('bx-718300', '100.00', 'RUB', false);

        self::assertSame(OrderVerifier::UNPAID, $verdict['status']);
    }

    public function testScanStopsAfterThreePages(): void
    {
        $page = [];
        for ($i = 0; $i < 100; $i++) {
            $page[] = FakeGateway::order(['id' => 'x' . $i, 'order_id' => 'other' . $i]);
        }

        $this->verifier([
            'api/order' => ['type' => 'error', 'desc' => 'Order not found', 'data' => []],
            'api/orders' => self::many($page),
        ], $gateway)->verify('bx-718300', '100.00', 'RUB', false);

        self::assertCount(4, $gateway->requests);
        self::assertSame([0, 100, 200], array_map(static function ($r) {
            return $r['body']['page'];
        }, array_slice($gateway->requests, 1)));
    }

    public function testOldGatewayHistoryIsIgnored(): void
    {
        $old = FakeGateway::order(['date_created' => '2024-01-01 10:00:00']);
        $verdict = $this->verifier([
            'api/order' => self::one($old),
            'api/orders' => self::many([$old]),
        ])->verify('bx-718300', '100.00', 'RUB', false, null, strtotime('2026-09-24 00:00:00'));

        self::assertSame(OrderVerifier::UNPAID, $verdict['status']);
    }

    public function testEarlierCorrectAttemptWinsOverMismatchedLatest(): void
    {
        $verdict = $this->verifier([
            'api/order' => self::one(FakeGateway::order(['id' => 'latest', 'amount' => 50])),
            'api/orders' => self::many([
                FakeGateway::order(['id' => 'latest', 'amount' => 50]),
                FakeGateway::order(['id' => 'first']),
            ]),
        ])->verify('bx-718300', '100.00', 'RUB', false);

        self::assertSame(OrderVerifier::PAID, $verdict['status']);
        self::assertSame('first', $verdict['order']['id']);
    }

    public function testMismatchReportedWhenNothingMatches(): void
    {
        $bad = FakeGateway::order(['amount' => 50]);
        $verdict = $this->verifier([
            'api/order' => self::one($bad),
            'api/orders' => self::many([$bad]),
        ])->verify('bx-718300', '100.00', 'RUB', false);

        self::assertSame(OrderVerifier::MISMATCH, $verdict['status']);
    }

    public function testCheckOfListedOrder(): void
    {
        $notBefore = strtotime('2026-09-24 00:00:00');

        self::assertSame(OrderVerifier::PAID, OrderVerifier::check(FakeGateway::order(), 'bx-718300', '100.00', 'RUB', false, $notBefore)['status']);
        self::assertSame('created_before_payment', OrderVerifier::check(
            FakeGateway::order(['date_created' => '2026-01-01 00:00:00']), 'bx-718300', '100.00', 'RUB', false, $notBefore
        )['reason']);
        self::assertSame('created_before_payment', OrderVerifier::check(FakeGateway::order(), 'bx-1', '100.00', 'RUB', false, $notBefore)['reason']);
        self::assertSame('amount', OrderVerifier::check(FakeGateway::order(['amount' => 99]), 'bx-718300', '100.00', 'RUB', false, $notBefore)['reason']);
    }

    public function testLatePaymentGoesToReview(): void
    {
        // старый счёт, внезапно оплаченный через полгода
        $late = FakeGateway::order(['date_created' => '2025-01-17 23:15:54', 'date_payed' => '2026-06-23 02:17:36']);

        self::assertSame('late_payment', OrderVerifier::check($late, 'bx-718300', '100.00', 'RUB', false, 0)['reason']);
    }

    public function testApiOutageIsNotUnpaid(): void
    {
        $this->expectException(ApiException::class);
        $this->verifier(['api/order' => null])->verify('bx-718300', '100.00', 'RUB', false);
    }
}
