<?php

namespace Smoservice\Roskassa;

/**
 * Сборка формы перехода на оплату. Не зависит от Bitrix.
 */
final class PaymentForm
{
    public const SOURCE = 'Bitrix smoservice.roskassa';

    /**
     * @param array{
     *     base_url: string, shop_id: string, secret: string, order_id: string, amount: string,
     *     currency: string, test?: bool, lang?: string, payment_system?: string, email?: string,
     *     phone?: string, name?: string, notify_url?: string, success_url?: string, fail_url?: string,
     *     items?: list<array{name: string, count: float|int|string, price: float|int|string}>,
     *     fallback_item_name?: string
     * } $in
     * @return array{action: string, fields: array<string, string>}
     */
    public static function build(array $in): array
    {
        $baseUrl = BaseUrl::normalize($in['base_url']);

        $fields = [
            'shop_id' => $in['shop_id'],
            'amount' => Money::format($in['amount']),
            'currency' => strtoupper($in['currency']),
            'order_id' => $in['order_id'],
        ];
        if (!empty($in['test'])) {
            $fields['test'] = '1';
        }
        $fields['sign'] = Signature::paymentForm($fields, $in['secret']);

        foreach (['lang', 'payment_system', 'email', 'phone', 'name', 'notify_url', 'success_url', 'fail_url'] as $key) {
            $value = isset($in[$key]) ? trim((string)$in[$key]) : '';
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }
        if (isset($fields['lang']) && !in_array($fields['lang'], ['ru', 'en'], true)) {
            unset($fields['lang']);
        }
        $fields['source'] = self::SOURCE;

        $action = $baseUrl . '/pay/';
        if (isset($fields['payment_system'], $fields['email'])) {
            // сразу на страницу выбранного способа — шлюзу нужна почта плательщика
            $action = $baseUrl . '/pay/form/';
            $fields['fields[email]'] = $fields['email'];
        }

        foreach (self::receipt($in, $fields['amount']) as $i => $item) {
            $fields['receipt[items][' . $i . '][name]'] = $item['name'];
            $fields['receipt[items][' . $i . '][count]'] = $item['count'];
            $fields['receipt[items][' . $i . '][price]'] = $item['price'];
        }

        return ['action' => $action, 'fields' => array_map('strval', $fields)];
    }

    /**
     * Состав чека. Шлюз приводит количество к целому, цену к float и сравнивает
     * (string)сумма_позиций != (string)amount. Повторяем ровно эту проверку: если она не пройдёт
     * (дробное количество, скидки, копейки с погрешностью float, частичная оплата) —
     * отправляем одну позицию на всю сумму, иначе покупатель увидит ошибку шлюза.
     *
     * @param array<string, mixed> $in
     * @return list<array{name: string, count: string, price: string}>
     */
    private static function receipt(array $in, string $amount): array
    {
        $items = [];
        $gatewayTotal = 0;
        $valid = true;
        foreach ($in['items'] ?? [] as $item) {
            $name = trim(strip_tags((string)($item['name'] ?? '')));
            $count = (float)($item['count'] ?? 0);
            $priceMinor = Money::toMinorOrZero($item['price'] ?? 0);
            if ($name === '' || $count < 1 || floor($count) !== $count || $priceMinor <= 0) {
                $valid = false;
                break;
            }
            $price = number_format($priceMinor / 100, 2, '.', '');
            $gatewayTotal += (float)$price * (int)$count;
            $items[] = ['name' => mb_substr($name, 0, 128), 'count' => (string)(int)$count, 'price' => $price];
        }

        if ($valid && $items !== [] && (string)$gatewayTotal == $amount) {
            return $items;
        }

        $name = trim(strip_tags((string)($in['fallback_item_name'] ?? '')));
        if ($name === '') {
            return [];
        }

        return [['name' => mb_substr($name, 0, 128), 'count' => '1', 'price' => $amount]];
    }
}
