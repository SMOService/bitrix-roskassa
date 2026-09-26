<?php

namespace Smoservice\Roskassa;

/**
 * Суммы сравниваются в копейках (целых), а не во float.
 */
final class Money
{
    /**
     * Сумма для формы оплаты: две цифры после точки, без разделителя тысяч.
     *
     * @param float|int|string $amount
     */
    public static function format($amount): string
    {
        return number_format(self::toMinor($amount) / 100, 2, '.', '');
    }

    /**
     * @param mixed $amount
     */
    public static function toMinor($amount): int
    {
        if (is_string($amount)) {
            $amount = trim($amount);
        }
        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException('Amount is not numeric');
        }

        return (int)round(((float)$amount) * 100);
    }

    /**
     * @param mixed $amount
     */
    public static function toMinorOrZero($amount): int
    {
        try {
            return self::toMinor($amount);
        } catch (\InvalidArgumentException $e) {
            return 0;
        }
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    public static function equals($a, $b): bool
    {
        try {
            return self::toMinor($a) === self::toMinor($b);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
}
