<?php

namespace Smoservice\Roskassa;

/**
 * Клиент REST API Roskassa: POST {base}/api/{method}/, тело — JSON,
 * Authorization: Bearer hmac_sha256(тело, api_key).
 */
final class ApiClient
{
    /** @var string */
    private $baseUrl;
    /** @var string */
    private $shopId;
    /** @var string */
    private $apiKey;
    /** @var callable(string, string, array<string, string>): ?string */
    private $transport;

    /** @var int */
    private static $lastNonce = 0;

    /**
     * @param callable(string, string, array<string, string>): ?string|null $transport
     *        (url, body, headers) => тело ответа или null при сетевой ошибке. Для тестов.
     */
    public function __construct(string $baseUrl, string $shopId, string $apiKey, ?callable $transport = null)
    {
        $this->baseUrl = BaseUrl::normalize($baseUrl);
        if ($shopId === '' || $apiKey === '') {
            throw new \InvalidArgumentException('Shop ID and API key are required');
        }
        $this->shopId = $shopId;
        $this->apiKey = $apiKey;
        $this->transport = $transport ?? [self::class, 'httpPost'];
    }

    /**
     * Последняя попытка оплаты по номеру платежа магазина (order_id в форме).
     *
     * @return array<string, mixed>|null null — такого платежа у Roskassa нет
     * @throws ApiException
     */
    public function getOrderByPaymentId(string $paymentId): ?array
    {
        $data = $this->request('order', ['payment_id' => $paymentId], true);

        return $data === null ? null : $data;
    }

    /**
     * Страница заказов магазина, новые сверху. offset — смещение (API называет его page), по 100 записей.
     *
     * @return list<array<string, mixed>>
     * @throws ApiException
     */
    public function listOrders(int $offset = 0, ?int $status = null): array
    {
        $params = ['page' => max(0, $offset)];
        if ($status !== null) {
            $params['status'] = $status;
        }

        $data = $this->request('orders', $params, true);
        if ($data === null) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<mixed>|null null — только для «Order not found», если $notFoundIsNull
     * @throws ApiException
     */
    private function request(string $method, array $params, bool $notFoundIsNull): ?array
    {
        $body = json_encode(
            ['shop_id' => $this->shopId, 'nonce' => self::nonce()] + $params,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($body === false) {
            throw new ApiException('Cannot encode request');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . Signature::api($body, $this->apiKey),
        ];

        $raw = ($this->transport)($this->baseUrl . '/api/' . $method . '/', $body, $headers);
        if (!is_string($raw) || $raw === '') {
            throw new ApiException('Roskassa API is unreachable');
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['type'])) {
            throw new ApiException('Unexpected Roskassa API response');
        }

        if ($json['type'] === 'success') {
            return is_array($json['data'] ?? null) ? $json['data'] : [];
        }

        $desc = is_string($json['desc'] ?? null) ? $json['desc'] : '';
        if ($notFoundIsNull && $desc === 'Order not found') {
            return null;
        }

        throw new ApiException('Roskassa API error: ' . ($desc !== '' ? $desc : 'unknown'));
    }

    /**
     * nonce обязан расти от запроса к запросу: миллисекунды + защита от повтора в пределах процесса.
     */
    private static function nonce(): int
    {
        $nonce = (int)floor(microtime(true) * 1000);
        if ($nonce <= self::$lastNonce) {
            $nonce = self::$lastNonce + 1;
        }
        self::$lastNonce = $nonce;

        return $nonce;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function httpPost(string $url, string $body, array $headers): ?string
    {
        if (class_exists(\Bitrix\Main\Web\HttpClient::class)) {
            $http = new \Bitrix\Main\Web\HttpClient([
                'socketTimeout' => 10,
                'streamTimeout' => 15,
                'redirect' => false,
                'disableSslVerification' => false,
            ]);
            foreach ($headers as $name => $value) {
                $http->setHeader($name, $value, true);
            }
            $response = $http->post($url, $body);
            $status = (int)$http->getStatus();

            return ($response === false || $status < 200 || $status >= 500) ? null : (string)$response;
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($response === false || $status < 200 || $status >= 500) ? null : (string)$response;
    }
}
