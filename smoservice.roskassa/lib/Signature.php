<?php

namespace Smoservice\Roskassa;

/**
 * Подписи протокола Roskassa (см. https://roskassa.net/docs/).
 *
 * - Форма оплаты: md5(http_build_query(ksort(shop_id, amount, currency, order_id[, test])) . secret)
 * - Уведомление:  md5(http_build_query(ksort(все поля, кроме sign)) . secret)
 * - REST API:     hmac_sha256(тело запроса как есть, api_key) в заголовке Authorization: Bearer
 *
 * Класс не зависит от Bitrix — покрыт тестами в tests/.
 */
final class Signature
{
    private const FORM_FIELDS = ['shop_id', 'amount', 'currency', 'order_id'];

    /**
     * Подпись формы оплаты. Поле test участвует в подписи, только если оно передаётся в форме.
     *
     * @param array<string, scalar> $fields
     */
    public static function paymentForm(array $fields, string $secret): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Secret key is empty');
        }

        $keys = self::FORM_FIELDS;
        if (array_key_exists('test', $fields)) {
            $keys[] = 'test';
        }

        $data = [];
        foreach ($keys as $key) {
            if (!isset($fields[$key]) || (string)$fields[$key] === '') {
                throw new \InvalidArgumentException('Required field is empty: ' . $key);
            }
            $data[$key] = (string)$fields[$key];
        }
        ksort($data);

        return md5(http_build_query($data) . $secret);
    }

    /**
     * Ожидаемая подпись уведомления о платеже.
     *
     * @param array<string, mixed> $data POST-данные уведомления как пришли (сырые, без фильтров)
     */
    public static function notification(array $data, string $secret): string
    {
        unset($data['sign']);
        ksort($data);

        return md5(http_build_query($data) . $secret);
    }

    /**
     * Проверка подписи уведомления. Fail-closed: пустой секрет или битая подпись — false.
     *
     * @param array<string, mixed> $data
     */
    public static function verifyNotification(array $data, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $incoming = $data['sign'] ?? '';
        if (!is_string($incoming) || !preg_match('/^[a-f0-9]{32}$/i', $incoming)) {
            return false;
        }

        return hash_equals(self::notification($data, $secret), strtolower($incoming));
    }

    public static function api(string $body, string $apiKey): string
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('API key is empty');
        }

        return hash_hmac('sha256', $body, $apiKey);
    }
}
