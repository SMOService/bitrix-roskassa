<?php

namespace Smoservice\Roskassa\Tests;

use PHPUnit\Framework\TestCase;
use Smoservice\Roskassa\ApiClient;
use Smoservice\Roskassa\ApiException;

final class ApiClientTest extends TestCase
{
    public function testRequestIsSignedAndAddressedToMirror(): void
    {
        $gateway = new FakeGateway(['api/order' => ['type' => 'success', 'desc' => '', 'data' => FakeGateway::order()]]);
        $client = new ApiClient('https://roskassa.net/', 'SHOP', 'api-key', $gateway);

        $order = $client->getOrderByPaymentId('bx-718300');

        self::assertSame('bx-718300', $order['order_id']);
        $request = $gateway->requests[0];
        self::assertSame('https://roskassa.net/api/order/', $request['url']);
        self::assertSame('SHOP', $request['body']['shop_id']);
        self::assertSame('bx-718300', $request['body']['payment_id']);
        self::assertSame('Bearer ' . hash_hmac('sha256', $request['raw'], 'api-key'), $request['headers']['Authorization']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
    }

    public function testNonceGrows(): void
    {
        $gateway = new FakeGateway(['api/orders' => ['type' => 'success', 'desc' => '', 'data' => []]]);
        $client = new ApiClient('https://roskassa.net', 'SHOP', 'k', $gateway);

        $client->listOrders();
        $client->listOrders();

        self::assertGreaterThan($gateway->requests[0]['body']['nonce'], $gateway->requests[1]['body']['nonce']);
    }

    public function testListOrdersPassesOffsetAndStatus(): void
    {
        $gateway = new FakeGateway(['api/orders' => ['type' => 'success', 'desc' => '', 'data' => [FakeGateway::order()]]]);
        $client = new ApiClient('https://roskassa.net', 'SHOP', 'k', $gateway);

        self::assertCount(1, $client->listOrders(200, 1));
        self::assertSame(200, $gateway->requests[0]['body']['page']);
        self::assertSame(1, $gateway->requests[0]['body']['status']);
    }

    public function testOrderNotFoundIsNull(): void
    {
        $gateway = new FakeGateway(['api/order' => ['type' => 'error', 'desc' => 'Order not found', 'data' => []]]);

        self::assertNull((new ApiClient('https://roskassa.net', 'SHOP', 'k', $gateway))->getOrderByPaymentId('1'));
    }

    public function testEmptyListIsEmptyArray(): void
    {
        $gateway = new FakeGateway(['api/orders' => ['type' => 'error', 'desc' => 'Order not found', 'data' => []]]);

        self::assertSame([], (new ApiClient('https://roskassa.net', 'SHOP', 'k', $gateway))->listOrders());
    }

    /**
     * @dataProvider brokenResponses
     * @param mixed $response
     */
    public function testBrokenResponsesThrow($response): void
    {
        $gateway = new FakeGateway(['api/order' => $response]);

        $this->expectException(ApiException::class);
        (new ApiClient('https://roskassa.net', 'SHOP', 'k', $gateway))->getOrderByPaymentId('1');
    }

    public function brokenResponses(): array
    {
        return [
            'network down' => [null],
            'html page' => ['<html>Just a moment...</html>'],
            'auth error' => [['type' => 'error', 'desc' => 'Wrong authorization token', 'data' => []]],
            'no type' => [['data' => []]],
        ];
    }

    public function testRequiresCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiClient('https://roskassa.net', 'SHOP', '', static function () {
            return null;
        });
    }
}
