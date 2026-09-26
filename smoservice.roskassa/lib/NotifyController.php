<?php

namespace Smoservice\Roskassa;

use Bitrix\Main\Context;
use Bitrix\Sale\Internals\PaymentTable;
use Bitrix\Sale\PaySystem;

/**
 * Приём уведомления о платеже: /bitrix/tools/smoservice_roskassa_notify.php?ps=ID платёжной системы.
 *
 * До ядра доходит только уведомление с верной подписью и номером платежа этой платёжной системы:
 * иначе ядро пометило бы чужой платёж как проблемный и пересохранило заказ по любому мусорному
 * запросу. Проведение — штатным PaySystem\Service::processRequest под блокировкой платежа.
 */
final class NotifyController
{
    /** @var int|null платёж, который сейчас проводится под блокировкой */
    private static $lockedPaymentId;

    public static function lockedPaymentId(): ?int
    {
        return self::$lockedPaymentId;
    }

    public static function handle(): void
    {
        try {
            [$status, $body] = self::process();
        } catch (\Throwable $e) {
            PaySystem\Logger::addError('Roskassa: notification failed: ' . get_class($e) . ': ' . $e->getMessage());
            [$status, $body] = [500, 'FAIL'];
        }

        self::respond($status, $body);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function process(): array
    {
        $request = Context::getCurrent()->getRequest();
        if (!$request->isPost()) {
            return [405, 'FAIL'];
        }

        $psId = (int)$request->getQuery('ps');
        $item = $psId > 0 ? PaySystem\Manager::getById($psId) : false;
        if (!is_array($item) || ($item['ACTION_FILE'] ?? '') !== Handler::ACTION_FILE) {
            return [400, 'FAIL'];
        }

        $post = Handler::postData($request);
        $orderRef = is_string($post['order_id'] ?? null) ? $post['order_id'] : '';
        $paymentId = Config::parsePaymentId($orderRef);
        if ($paymentId === null) {
            return [400, 'FAIL'];
        }

        $row = PaymentTable::getList([
            'filter' => ['=ID' => $paymentId],
            'select' => ['ID', 'ORDER_ID', 'PAY_SYSTEM_ID', 'PERSON_TYPE_ID' => 'ORDER.PERSON_TYPE_ID'],
        ])->fetch();
        if (!is_array($row) || (int)$row['PAY_SYSTEM_ID'] !== $psId) {
            PaySystem\Logger::addError('Roskassa: notification for payment ' . $paymentId . ' that does not belong to pay system ' . $psId);

            return [400, 'FAIL'];
        }

        $cfg = ConfigLoader::forPaySystem($psId, (int)$row['PERSON_TYPE_ID']);
        $shopId = is_string($post['shop_id'] ?? null) ? $post['shop_id'] : '';
        if (!Signature::verifyNotification($post, $cfg->secret)
            || $cfg->shopId === '' || !hash_equals($cfg->shopId, $shopId)
            || !hash_equals($cfg->gatewayOrderId($paymentId), $orderRef)) {
            PaySystem\Logger::addError('Roskassa: notification with invalid signature, shop or order number (payment ' . $paymentId . ')');

            return [400, 'FAIL'];
        }

        $gatewayId = is_string($post['id'] ?? null) ? $post['id'] : '';

        // проведение и проверка итога — под одной блокировкой, иначе сверка в это окно
        // увидела бы «в журнале проведено, платёж не оплачен» и подняла ложную тревогу
        $outcome = PaymentLock::run((int)$row['ORDER_ID'], static function () use ($item, $request, $paymentId, $gatewayId) {
            self::$lockedPaymentId = $paymentId;
            ob_start();
            try {
                $result = (new PaySystem\Service($item))->processRequest($request);
            } finally {
                ob_end_clean();
                self::$lockedPaymentId = null;
            }

            // ядро возвращает успех, даже если заказ не сохранился — проверяем итог по БД
            if (self::isPaid($paymentId)) {
                return $result->isSuccess() ? [200, 'OK'] : [400, 'FAIL'];
            }

            $journal = $gatewayId !== '' ? CreditTable::find($gatewayId) : null;
            if ($journal !== null && $journal['STATUS'] === CreditTable::STATUS_CREDITED && (int)$journal['PAYMENT_ID'] === $paymentId) {
                Handler::reportSaveFailed($gatewayId, $paymentId, implode('; ', $result->getErrorMessages()) ?: 'order was not saved');
            }

            return [400, 'FAIL'];
        });

        if ($outcome === null) {
            // заказ сейчас обрабатывает другой процесс; оплату подхватит сверка
            PaySystem\Logger::addError('Roskassa: notification for payment ' . $paymentId . ' skipped: order is locked, reconciliation will pick it up');

            return [409, 'FAIL'];
        }

        return $outcome;
    }

    private static function isPaid(int $paymentId): bool
    {
        $row = PaymentTable::getList([
            'filter' => ['=ID' => $paymentId],
            'select' => ['PAID'],
        ])->fetch();

        return is_array($row) && $row['PAID'] === 'Y';
    }

    private static function respond(int $status, string $body): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
    }
}
