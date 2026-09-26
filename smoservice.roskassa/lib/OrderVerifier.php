<?php

namespace Smoservice\Roskassa;

/**
 * Независимое подтверждение оплаты у Roskassa по API («подпись ≠ оплата»).
 *
 * /api/order/ по payment_id возвращает только ПОСЛЕДНЮЮ попытку оплаты. Если покупатель
 * открывал форму дважды и оплатил первую попытку, последняя будет неоплаченной —
 * поэтому при промахе просматриваем оплаченные заказы магазина через /api/orders/.
 */
final class OrderVerifier
{
    public const PAID = 'paid';
    public const UNPAID = 'unpaid';
    public const MISMATCH = 'mismatch';

    /** оплата спустя больше недели после создания заказа шлюза — не проводим сами, только ручной разбор */
    public const MAX_PAY_DELAY = 604800;

    private const SCAN_PAGES = 3;
    private const PAGE_SIZE = 100;

    /** @var ApiClient */
    private $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * @param string $orderRef номер заказа магазина у шлюза (order_id формы)
     * @param string|null $gatewayOrderId id заказа Roskassa из уведомления (если есть) — должен совпасть
     * @param int $notBefore unix-время: заказы шлюза, созданные раньше, не наши (старая история магазина)
     * @return array{status: string, order: array<string, mixed>|null, reason: string}
     * @throws ApiException
     */
    public function verify(
        string $orderRef,
        string $expectedAmount,
        string $expectedCurrency,
        bool $allowTest,
        ?string $gatewayOrderId = null,
        int $notBefore = 0
    ): array {
        $mismatch = null;

        $latest = $this->api->getOrderByPaymentId($orderRef);
        if ($latest !== null && self::belongs($latest, $orderRef, $gatewayOrderId, $notBefore)) {
            $verdict = self::evaluate($latest, $expectedAmount, $expectedCurrency, $allowTest);
            if ($verdict['status'] === self::PAID) {
                return $verdict;
            }
            if ($verdict['status'] === self::MISMATCH) {
                $mismatch = $verdict;
            }
        }

        // более ранняя попытка могла быть оплачена верно, даже если последняя не сходится
        for ($page = 0; $page < self::SCAN_PAGES; $page++) {
            $orders = $this->api->listOrders($page * self::PAGE_SIZE, 1);
            foreach ($orders as $order) {
                if (!self::belongs($order, $orderRef, $gatewayOrderId, $notBefore)) {
                    continue;
                }
                $verdict = self::evaluate($order, $expectedAmount, $expectedCurrency, $allowTest);
                if ($verdict['status'] === self::PAID) {
                    return $verdict;
                }
                if ($verdict['status'] === self::MISMATCH && $mismatch === null) {
                    $mismatch = $verdict;
                }
            }
            if (count($orders) < self::PAGE_SIZE) {
                break;
            }
        }

        return $mismatch ?? ['status' => self::UNPAID, 'order' => $latest, 'reason' => 'no_paid_order'];
    }

    /**
     * Проверка уже найденного в списке заказа шлюза (сверка идёт от списка оплат).
     *
     * @param array<string, mixed> $order
     * @return array{status: string, order: array<string, mixed>, reason: string}
     */
    public static function check(
        array $order,
        string $orderRef,
        string $expectedAmount,
        string $expectedCurrency,
        bool $allowTest,
        int $notBefore
    ): array {
        if (!self::belongs($order, $orderRef, null, $notBefore)) {
            return ['status' => self::MISMATCH, 'order' => $order, 'reason' => 'created_before_payment'];
        }

        return self::evaluate($order, $expectedAmount, $expectedCurrency, $allowTest);
    }

    /**
     * @param array<string, mixed> $order
     */
    private static function belongs(array $order, string $orderRef, ?string $gatewayOrderId, int $notBefore): bool
    {
        if ((string)($order['order_id'] ?? '') !== $orderRef) {
            return false;
        }
        if ($gatewayOrderId !== null && $gatewayOrderId !== '' && (string)($order['id'] ?? '') !== $gatewayOrderId) {
            return false;
        }
        if ($notBefore > 0) {
            $created = strtotime((string)($order['date_created'] ?? ''));
            if ($created === false || $created < $notBefore) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $order
     * @return array{status: string, order: array<string, mixed>, reason: string}
     */
    private static function evaluate(array $order, string $expectedAmount, string $expectedCurrency, bool $allowTest): array
    {
        $paid = (int)($order['status'] ?? 0) === 1 && !empty($order['date_payed']);
        if (!$paid) {
            return ['status' => self::UNPAID, 'order' => $order, 'reason' => 'status_not_paid'];
        }

        $created = strtotime((string)($order['date_created'] ?? ''));
        $payed = strtotime((string)$order['date_payed']);
        if ($created === false || $payed === false || $payed - $created > self::MAX_PAY_DELAY) {
            // старые неоплаченные счета, внезапно ставшие «оплаченными», — признак сбоя или ручной
            // правки на стороне шлюза: такие деньги сверяются человеком
            return ['status' => self::MISMATCH, 'order' => $order, 'reason' => 'late_payment'];
        }

        if ((int)($order['test_order'] ?? 0) === 1 && !$allowTest) {
            return ['status' => self::MISMATCH, 'order' => $order, 'reason' => 'test_order'];
        }

        if (!Money::equals($order['amount'] ?? null, $expectedAmount)) {
            return ['status' => self::MISMATCH, 'order' => $order, 'reason' => 'amount'];
        }

        $currency = strtoupper((string)($order['currency'] ?? ''));
        if ($currency === '' || $currency !== strtoupper($expectedCurrency)) {
            return ['status' => self::MISMATCH, 'order' => $order, 'reason' => 'currency'];
        }

        return ['status' => self::PAID, 'order' => $order, 'reason' => ''];
    }
}
