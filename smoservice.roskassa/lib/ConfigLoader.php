<?php

namespace Smoservice\Roskassa;

use Bitrix\Main\Config\Option;
use Bitrix\Sale\BusinessValue;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem;

/**
 * Чтение настроек платёжной системы из бизнес-значений sale. Один путь для формы,
 * уведомления и сверки — чтобы все три видели одни и те же ключи.
 */
final class ConfigLoader
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function forPaySystem(int $paySystemId, ?int $personTypeId): Config
    {
        $consumer = PaySystem\Service::PAY_SYSTEM_PREFIX . $paySystemId;

        return Config::fromReader(static function (string $code) use ($consumer, $personTypeId) {
            return BusinessValue::get($code, $consumer, $personTypeId ?: null);
        }, self::defaultPrefix());
    }

    /**
     * Префикс по умолчанию — свой для каждого сайта (генерируется при установке): два сайта
     * на одном магазине Roskassa с одинаковым префиксом приняли бы оплаты друг друга.
     */
    public static function defaultPrefix(): string
    {
        $prefix = (string)Option::get('smoservice.roskassa', 'default_order_prefix', '');
        if ($prefix === '') {
            // без своего префикса сайт путал бы оплаты с другими сайтами на том же магазине
            throw new \InvalidArgumentException('Module smoservice.roskassa is not installed properly: reinstall it to generate the order prefix');
        }

        return $prefix;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function forPayment(Payment $payment): Config
    {
        return self::forPaySystem((int)$payment->getPaymentSystemId(), (int)$payment->getPersonTypeId());
    }
}
