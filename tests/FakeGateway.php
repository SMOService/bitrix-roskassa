<?php

namespace Smoservice\Roskassa\Tests;

/**
 * Поддельный транспорт для ApiClient: отвечает заготовленными ответами и запоминает запросы.
 */
final class FakeGateway
{
    /** @var list<array{url: string, body: array, raw: string, headers: array}> */
    public $requests = [];
    /** @var array<string, mixed> метод => ответ (array — будет JSON, string — как есть, null — сеть упала) */
    private $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function __invoke(string $url, string $body, array $headers): ?string
    {
        $this->requests[] = ['url' => $url, 'body' => json_decode($body, true), 'raw' => $body, 'headers' => $headers];
        $method = trim((string)parse_url($url, PHP_URL_PATH), '/');
        $response = $this->responses[$method] ?? ['type' => 'error', 'desc' => 'Method not found', 'data' => []];
        if (is_callable($response)) {
            $response = $response(json_decode($body, true));
        }

        return is_array($response) ? json_encode($response) : $response;
    }

    public static function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 'a1b2c3d4e5f6',
            'shop_id' => 'SHOP',
            'date_created' => '2026-09-25 10:00:00',
            'date_payed' => '2026-09-25 10:01:00',
            'status' => 1,
            'payment_system_id' => 11,
            'currency_id' => 1,
            'currency' => 'RUB',
            'amount' => 100,
            'test_order' => 0,
            'order_id' => 'bx-718300',
        ], $overrides);
    }
}
