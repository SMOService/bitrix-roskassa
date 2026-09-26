<?php

namespace Smoservice\Roskassa;

use Bitrix\Main\Application;

/**
 * Именованная блокировка БД на заказ: уведомление и агент сверки не должны проводить
 * платежи одного заказа одновременно (двойное зачисление, параллельное сохранение заказа).
 */
final class PaymentLock
{
    private const TIMEOUT = 15;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T|null null — блокировку взять не удалось (заказ уже обрабатывается)
     */
    public static function run(int $orderId, callable $callback)
    {
        $connection = Application::getConnection();
        $name = 'smoservice_roskassa_order_' . $orderId;

        if (!$connection->lock($name, self::TIMEOUT)) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $connection->unlock($name);
        }
    }
}
