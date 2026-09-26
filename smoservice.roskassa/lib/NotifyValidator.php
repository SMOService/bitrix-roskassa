<?php

namespace Smoservice\Roskassa;

/**
 * Проверка уведомления о платеже до обращения к API.
 * Подпись — необходимое, но не достаточное условие: после неё платёж
 * всё равно подтверждается запросом к API (см. OrderVerifier).
 */
final class NotifyValidator
{
    private const REQUIRED = ['id', 'shop_id', 'amount', 'order_id', 'currency', 'status', 'sign'];

    /**
     * @param array<string, mixed> $post сырые POST-данные уведомления
     * @return string|null null — уведомление корректно, иначе код причины отказа
     */
    public static function validate(
        array $post,
        string $secret,
        string $shopId,
        string $expectedOrderId,
        string $expectedAmount,
        string $expectedCurrency,
        bool $testMode
    ): ?string {
        foreach (self::REQUIRED as $key) {
            if (!isset($post[$key]) || !is_string($post[$key]) || $post[$key] === '') {
                return 'missing_field:' . $key;
            }
        }

        if (!Signature::verifyNotification($post, $secret)) {
            return 'bad_signature';
        }

        if ($shopId === '' || !hash_equals($shopId, $post['shop_id'])) {
            return 'shop_mismatch';
        }

        if ($expectedOrderId === '' || !hash_equals($expectedOrderId, $post['order_id'])) {
            return 'order_mismatch';
        }

        if ($post['status'] !== '1') {
            return 'not_paid';
        }

        $isTest = isset($post['test']) && (string)$post['test'] === '1';
        if ($isTest && !$testMode) {
            return 'test_payment_in_live_mode';
        }

        if (Money::toMinorOrZero($post['amount']) <= 0 || !Money::equals($post['amount'], $expectedAmount)) {
            return 'amount_mismatch';
        }

        if (strtoupper($post['currency']) !== strtoupper($expectedCurrency)) {
            return 'currency_mismatch';
        }

        return null;
    }
}
