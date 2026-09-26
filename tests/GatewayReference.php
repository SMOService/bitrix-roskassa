<?php

namespace Smoservice\Roskassa\Tests;

/**
 * Эталон: как шлюз Roskassa сам считает подписи — серверная проверка подписи формы и
 * формирование уведомления магазину. Тесты ловят расхождение модуля с реальным сервером.
 */
final class GatewayReference
{
    /** Серверная проверка подписи входящей формы оплаты */
    public static function formSignValid(array $income, string $secret): bool
    {
        $required = ['shop_id', 'amount', 'order_id', 'currency', 'test'];
        if (!isset($income['test'])) {
            $required = array_values(array_diff($required, ['test']));
        }
        $prepared = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $income) || $income[$key] === null || $income[$key] === '') {
                return false;
            }
            $prepared[$key] = $income[$key];
        }
        ksort($prepared);

        return md5(http_build_query($prepared) . $secret) === ($income['sign'] ?? null);
    }

    /** Тело уведомления, которое шлюз POST-ом отправляет магазину */
    public static function notification(array $overrides, string $secret): array
    {
        $data = array_merge([
            'id' => 'a1b2c3d4e5f6',
            'shop_id' => 'D0F98E7D7742609DC508D86BB7500914',
            'date_created' => '2026-09-25 10:00:00',
            'date_payed' => '2026-09-25 10:01:00',
            'status' => 1,
            'payment_system' => 11,
            'currency_id' => 1,
            'currency' => 'RUB',
            'amount' => 100,
            'fee' => 4.5,
            'order_id' => 'bx-718300',
            'public_key' => 'D0F98E7D7742609DC508D86BB7500914',
        ], $overrides);
        $data = array_filter($data, static function ($v) {
            return $v !== null;
        });
        ksort($data);
        $data['sign'] = md5(http_build_query($data) . $secret);

        // так данные видит PHP на стороне магазина: всё — строки
        return array_map('strval', $data);
    }
}
