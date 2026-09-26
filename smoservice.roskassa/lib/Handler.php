<?php

namespace Smoservice\Roskassa;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\SiteTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem;

Loc::loadMessages(__FILE__);

/**
 * Обработчик платёжной системы Roskassa для модуля sale.
 * Подключается классом \Sale\Handlers\PaySystem\RoskassanetHandler из папки обработчика.
 *
 * Правила проведения оплаты (одинаковы для уведомления и для сверки):
 *  1. уведомление принимается только через NotifyController под блокировкой платежа;
 *     общий sale_ps_result.php этот обработчик не выбирает никогда (isMyResponse = false);
 *  2. подпись ≠ оплата: заказ шлюза должен быть оплачен по данным API;
 *  3. одна оплата шлюза проводится один раз (CreditTable, первичный ключ = id шлюза);
 *  4. платёж, который уже был оплачен и потом снят (админом, возвратом), автоматически
 *     повторно не проводится — только ручной разбор.
 */
class Handler extends PaySystem\ServiceHandler
{
    public const ACTION_FILE = 'roskassanet';
    public const NOTIFY_PATH = '/bitrix/tools/smoservice_roskassa_notify.php';

    /** запас на расхождение часов сайта и шлюза при сверке даты заказа */
    private const CLOCK_SKEW = 86400;

    /**
     * @param Payment $payment
     * @param Request|null $request
     * @return PaySystem\ServiceResult
     */
    public function initiatePay(Payment $payment, Request $request = null)
    {
        $result = new PaySystem\ServiceResult();

        try {
            $cfg = ConfigLoader::forPayment($payment);
            $order = $this->order($payment);

            $form = PaymentForm::build([
                'base_url' => $cfg->baseUrl,
                'shop_id' => $cfg->shopId,
                'secret' => $cfg->secret,
                'order_id' => $cfg->gatewayOrderId((int)$payment->getId()),
                'amount' => Money::format($payment->getSum()),
                'currency' => (string)$payment->getField('CURRENCY'),
                'test' => $cfg->testMode,
                'lang' => $cfg->lang,
                'payment_system' => $cfg->paymentSystem,
                'email' => $this->payerEmail($order),
                'phone' => $this->payerProperty($order, 'getPhone'),
                'name' => $this->payerProperty($order, 'getPayerName'),
                'notify_url' => $this->notifyUrl($cfg, $order),
                'success_url' => $cfg->successUrl,
                'fail_url' => $cfg->failUrl,
                'items' => $this->basketItems($order),
                'fallback_item_name' => Loc::getMessage(
                    'SMOSERVICE_ROSKASSA_RECEIPT_ITEM',
                    ['#NUMBER#' => (string)$order->getField('ACCOUNT_NUMBER')]
                ),
            ]);
        } catch (\InvalidArgumentException $e) {
            PaySystem\Logger::addError('Roskassa: initiatePay: ' . $e->getMessage());
            $result->addError(new Error(Loc::getMessage('SMOSERVICE_ROSKASSA_CONFIG_ERROR')));

            return $result;
        }

        $this->setExtraParams([
            'ACTION' => $form['action'],
            'FIELDS' => $form['fields'],
            'SUM' => $payment->getSum(),
            'CURRENCY' => (string)$payment->getField('CURRENCY'),
            'PAYMENT_NUMBER' => (string)$payment->getField('ACCOUNT_NUMBER'),
        ]);

        return $this->showTemplate($payment, 'template');
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPaymentIdFromRequest(Request $request)
    {
        $post = self::postData($request);

        return Config::parsePaymentId(is_string($post['order_id'] ?? null) ? $post['order_id'] : '');
    }

    /**
     * Общий sale_ps_result.php не должен выбирать этот обработчик ни при каких полях запроса:
     * там нет блокировки платежа, параллельные запросы провели бы оплату несколько раз.
     *
     * @param Request $request
     * @param int $paySystemId
     * @return bool
     */
    public static function isMyResponse(Request $request, $paySystemId)
    {
        return false;
    }

    /**
     * @param Payment $payment
     * @param Request $request
     * @return PaySystem\ServiceResult
     */
    public function processRequest(Payment $payment, Request $request)
    {
        $result = new PaySystem\ServiceResult();
        $paymentId = (int)$payment->getId();

        if (NotifyController::lockedPaymentId() !== $paymentId) {
            return $this->fail($result, 'processRequest outside NotifyController lock refused (payment ' . $paymentId . ')');
        }
        if ((int)$payment->getPaymentSystemId() !== (int)$this->service->getField('ID')) {
            return $this->fail($result, 'payment ' . $paymentId . ' belongs to another pay system');
        }

        try {
            $cfg = ConfigLoader::forPayment($payment);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($result, 'config: ' . $e->getMessage());
        }

        $post = self::postData($request);
        $currency = (string)$payment->getField('CURRENCY');
        $reason = NotifyValidator::validate(
            $post,
            $cfg->secret,
            $cfg->shopId,
            $cfg->gatewayOrderId($paymentId),
            Money::format($payment->getSum()),
            $currency,
            $this->testAllowed($payment, $cfg)
        );
        if ($reason !== null) {
            return $this->fail($result, 'notification rejected (' . $reason . ') for payment ' . $paymentId);
        }

        $gatewayId = (string)$post['id'];

        if ($payment->isPaid()) {
            if ($gatewayId !== '' && $gatewayId !== (string)$payment->getField('PS_INVOICE_ID')
                && CreditTable::claim($gatewayId, $paymentId, CreditTable::STATUS_DUPLICATE, (string)$post['amount'], $currency)) {
                PaySystem\Logger::addError('Roskassa: ' . self::duplicateMessage($payment, $gatewayId));
            }

            // повтор уже учтённого уведомления: подтверждаем без побочных эффектов
            return $result;
        }

        if ($this->order($payment)->isCanceled()) {
            $this->review($gatewayId, $paymentId, (string)$post['amount'], $currency, 'order is canceled');

            return $this->fail($result, 'order of payment ' . $paymentId . ' is canceled');
        }

        $verdict = $this->verify($payment, $cfg, $gatewayId);
        if ($verdict === null) {
            return $this->fail($result, 'API check unavailable for payment ' . $paymentId . ' — reconciliation will retry');
        }
        if ($verdict['status'] !== OrderVerifier::PAID) {
            return $this->fail($result, 'API does not confirm payment ' . $paymentId . ' (' . $verdict['status'] . ': ' . $verdict['reason'] . ')');
        }

        $blocked = $this->claimCredit($payment, $verdict['order'], $currency);
        if ($blocked !== null) {
            return $this->fail($result, $blocked);
        }

        $result->setPsData($this->psFields($verdict['order'], $currency));
        $result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);

        return $result;
    }

    /**
     * Сверка от шлюза (агент Reconciler, под блокировкой заказа): у шлюза есть оплаченный заказ
     * с номером этого платежа. Решаем, что с ним делать. Шлюз шлёт уведомление один раз,
     * поэтому это единственный способ не потерять оплату, уведомление о которой не дошло.
     *
     * @param array<string, mixed> $gatewayOrder запись из /api/orders/ (status = 1)
     * @return string credited | backfilled | duplicate | mismatch | review | save_failed | skipped
     */
    public function reconcileGatewayOrder(Payment $payment, array $gatewayOrder, Config $cfg): string
    {
        $paymentId = (int)$payment->getId();
        $gatewayId = (string)($gatewayOrder['id'] ?? '');
        $amount = (string)($gatewayOrder['amount'] ?? '');
        $currency = (string)$payment->getField('CURRENCY');
        if ($gatewayId === '') {
            return 'skipped';
        }

        if ($payment->isPaid()) {
            if ($gatewayId === (string)$payment->getField('PS_INVOICE_ID')) {
                // проведён этим же заказом шлюза до появления журнала — дописываем журнал
                CreditTable::claim($gatewayId, $paymentId, CreditTable::STATUS_CREDITED, $amount, $currency);

                return 'backfilled';
            }
            if (CreditTable::claim($gatewayId, $paymentId, CreditTable::STATUS_DUPLICATE, $amount, $currency)) {
                PaySystem\Logger::addError('Roskassa: ' . self::duplicateMessage($payment, $gatewayId) . ' (' . $amount . ' ' . $currency . ')');
            }

            return 'duplicate';
        }

        if ((int)$payment->getPaymentSystemId() !== (int)$this->service->getField('ID')) {
            return $this->review($gatewayId, $paymentId, $amount, $currency, 'payment was moved to another pay system after the buyer paid Roskassa');
        }
        try {
            $own = ConfigLoader::forPayment($payment);
        } catch (\InvalidArgumentException $e) {
            return $this->review($gatewayId, $paymentId, $amount, $currency, 'pay system of the payment is misconfigured: ' . $e->getMessage());
        }
        if ($own->shopId !== $cfg->shopId) {
            // деньги пришли в магазин, к которому платёж сейчас не относится (сменили способ оплаты)
            return $this->review($gatewayId, $paymentId, $amount, $currency, 'paid to Roskassa shop ' . $cfg->shopId . ', but the payment now belongs to shop ' . $own->shopId);
        }
        if ($this->order($payment)->isCanceled()) {
            return $this->review($gatewayId, $paymentId, $amount, $currency, 'order is canceled');
        }

        $billed = $payment->getField('DATE_BILL');
        // дальше — только настройки самого платежа: у платёжных систем одного магазина
        // может различаться, например, тестовый режим
        $verdict = OrderVerifier::check(
            $gatewayOrder,
            $own->gatewayOrderId($paymentId),
            Money::format($payment->getSum()),
            $currency,
            $this->testAllowed($payment, $own),
            $billed instanceof DateTime ? $billed->getTimestamp() - self::CLOCK_SKEW : 0
        );
        if ($verdict['status'] !== OrderVerifier::PAID) {
            if (CreditTable::claim($gatewayId, $paymentId, CreditTable::STATUS_MISMATCH, $amount, $currency)) {
                PaySystem\Logger::addError('Roskassa: reconcile: gateway order ' . $gatewayId . ' is paid but does not match payment ' . $paymentId . ' (' . $verdict['reason'] . ') — manual review');
            }

            return 'mismatch';
        }

        $blocked = $this->claimCredit($payment, $gatewayOrder, $currency);
        if ($blocked !== null) {
            return 'review';
        }

        try {
            $event = new Event('sale', PaySystem\Service::EVENT_ON_BEFORE_PAYMENT_PAID, [
                'payment' => $payment,
                'status' => 'Y',
                'pay_system_id' => $this->service->getField('ID'),
            ]);
            $event->send();

            $paid = $payment->setPaid('Y');
            $fields = $paid->isSuccess() ? $payment->setFields($this->psFields($gatewayOrder, $currency)) : $paid;
            $saved = $fields->isSuccess() ? $this->order($payment)->save() : $fields;
            $error = $saved->isSuccess() ? '' : implode('; ', $saved->getErrorMessages());
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }

        if (!self::isPaidInDb($paymentId)) {
            self::reportSaveFailed($gatewayId, $paymentId, $error);

            return 'save_failed';
        }
        if ($error !== '') {
            PaySystem\Logger::addError('Roskassa: reconcile: payment ' . $paymentId . ' is saved as paid, but the order save reported: ' . $error);
        }

        if (class_exists(PaySystem\PullManager::class)) {
            PaySystem\PullManager::onSuccessfulPayment($payment);
        }
        PaySystem\Logger::addError('Roskassa: reconcile: payment ' . $paymentId . ' marked paid from gateway order ' . $gatewayId . ' — the notification was lost');

        return 'credited';
    }

    /**
     * Платёж уже оплачен, а у шлюза оплачен ещё один заказ с его номером. Если платёж проведён
     * другим нашим заказом шлюза — это двойная оплата. Если отмечен вручную или другой платёжной
     * системой — возможно, вручную отметили как раз этот заказ шлюза: проверить, прежде чем возвращать.
     */
    private static function duplicateMessage(Payment $payment, string $gatewayId): string
    {
        $paymentId = (int)$payment->getId();
        $ours = strpos((string)$payment->getField('PS_STATUS_DESCRIPTION'), 'Roskassa ') === 0
            && (string)$payment->getField('PS_INVOICE_ID') !== '';

        return $ours
            ? 'DOUBLE PAYMENT — payment ' . $paymentId . ' is already paid by gateway order ' . $payment->getField('PS_INVOICE_ID') . ', gateway order ' . $gatewayId . ' paid it again: refund the buyer'
            : 'payment ' . $paymentId . ' was marked paid not through Roskassa, and gateway order ' . $gatewayId . ' is paid too — check whether it was marked paid for this very gateway order before refunding';
    }

    /**
     * Оплата застолблена в журнале как проведённая, а платёж не оплачен: громко сообщаем
     * и переводим запись в save_failed, чтобы её нашли при разборе.
     */
    public static function reportSaveFailed(string $gatewayId, int $paymentId, string $error): void
    {
        CreditTable::setStatus($gatewayId, CreditTable::STATUS_SAVE_FAILED);
        PaySystem\Logger::addError('Roskassa: PAID AT GATEWAY BUT NOT CREDITED — payment ' . $paymentId . ', gateway order ' . $gatewayId . ($error !== '' ? ': ' . $error : '') . ' — manual review, not retried automatically');
    }

    private static function isPaidInDb(int $paymentId): bool
    {
        $row = \Bitrix\Sale\Internals\PaymentTable::getList([
            'filter' => ['=ID' => $paymentId],
            'select' => ['PAID'],
        ])->fetch();

        return is_array($row) && $row['PAID'] === 'Y';
    }

    private function review(string $gatewayId, int $paymentId, string $amount, string $currency, string $why): string
    {
        if (CreditTable::claim($gatewayId, $paymentId, CreditTable::STATUS_REVIEW, $amount, $currency)) {
            PaySystem\Logger::addError('Roskassa: gateway order ' . $gatewayId . ' is paid, payment ' . $paymentId . ' not credited automatically: ' . $why . ' — manual review');
        }

        return 'review';
    }

    /**
     * @return array
     */
    public function getCurrencyList()
    {
        return ['RUB', 'USD', 'EUR'];
    }

    /**
     * Последние проверки перед зачислением. null — можно проводить (оплата шлюза застолблена).
     *
     * @param array<string, mixed> $gatewayOrder
     */
    private function claimCredit(Payment $payment, array $gatewayOrder, string $currency): ?string
    {
        $paymentId = (int)$payment->getId();
        $gatewayId = (string)($gatewayOrder['id'] ?? '');
        $amount = (string)($gatewayOrder['amount'] ?? '');

        if ($gatewayId === '') {
            return 'gateway order id is empty for payment ' . $paymentId;
        }

        if (self::wasPaidBefore($payment)) {
            if (CreditTable::claim($gatewayId, $paymentId, CreditTable::STATUS_REVIEW, $amount, $currency)) {
                PaySystem\Logger::addError('Roskassa: payment ' . $paymentId . ' was paid before and then unpaid; gateway order ' . $gatewayId . ' is not credited automatically — manual review');
            }

            return 'payment ' . $paymentId . ' was paid before — manual review';
        }

        if (!CreditTable::claim($gatewayId, $paymentId, CreditTable::STATUS_CREDITED, $amount, $currency)) {
            $row = CreditTable::find($gatewayId);
            if ($row !== null && $row['STATUS'] === CreditTable::STATUS_CREDITED && (int)$row['PAYMENT_ID'] === $paymentId) {
                self::reportSaveFailed($gatewayId, $paymentId, 'journal says credited, payment is unpaid');
            }

            return 'gateway order ' . $gatewayId . ' is already in the payment journal (payment ' . $paymentId . ')';
        }

        return null;
    }

    /**
     * Платёж уже был оплачен когда-то (любым способом) и потом снят с оплаты.
     * Поля PS_* других платёжных систем не в счёт: многие пишут PS_INVOICE_ID ещё при создании
     * счёта, а при смене способа оплаты строка платежа остаётся той же.
     */
    private static function wasPaidBefore(Payment $payment): bool
    {
        $isReturn = (string)$payment->getField('IS_RETURN');
        $ours = strpos((string)$payment->getField('PS_STATUS_DESCRIPTION'), 'Roskassa ') === 0;

        return ($ours && (string)$payment->getField('PS_STATUS') === 'Y')
            || ($isReturn !== '' && $isReturn !== 'N')
            || $payment->getField('DATE_PAID') !== null
            || (int)$payment->getField('EMP_PAID_ID') > 0;
    }

    /**
     * Тестовые платежи засчитываются только в тестовом режиме и только для заказов администраторов:
     * иначе покупатель мог бы переключить неоплаченный платёж на тестовую платёжную систему.
     */
    private function testAllowed(Payment $payment, Config $cfg): bool
    {
        if (!$cfg->testMode) {
            return false;
        }
        $userId = (int)$this->order($payment)->getUserId();

        return $userId > 0 && in_array('1', array_map('strval', \CUser::GetUserGroup($userId)), true);
    }

    /**
     * @return array{status: string, order: array<string, mixed>|null, reason: string}|null null — API недоступно
     */
    private function verify(Payment $payment, Config $cfg, ?string $gatewayOrderId): ?array
    {
        $client = $cfg->apiClient();
        if ($client === null) {
            PaySystem\Logger::addError('Roskassa: API key is not set — payment ' . $payment->getId() . ' cannot be confirmed');

            return null;
        }

        $billed = $payment->getField('DATE_BILL');
        $notBefore = $billed instanceof DateTime ? $billed->getTimestamp() - self::CLOCK_SKEW : 0;

        try {
            return (new OrderVerifier($client))->verify(
                $cfg->gatewayOrderId((int)$payment->getId()),
                Money::format($payment->getSum()),
                (string)$payment->getField('CURRENCY'),
                $this->testAllowed($payment, $cfg),
                $gatewayOrderId,
                $notBefore
            );
        } catch (ApiException $e) {
            PaySystem\Logger::addError('Roskassa: ' . $e->getMessage() . ' (payment ' . $payment->getId() . ')');

            return null;
        }
    }

    /**
     * @param array<string, mixed> $gatewayOrder
     * @return array<string, mixed>
     */
    private function psFields(array $gatewayOrder, string $currency): array
    {
        $gatewayId = (string)($gatewayOrder['id'] ?? '');

        return [
            'PS_STATUS' => 'Y',
            'PS_STATUS_CODE' => '1',
            'PS_STATUS_DESCRIPTION' => mb_substr('Roskassa ' . $gatewayId, 0, 250),
            'PS_STATUS_MESSAGE' => mb_substr('payment_system=' . (string)($gatewayOrder['payment_system_id'] ?? ''), 0, 250),
            'PS_INVOICE_ID' => mb_substr($gatewayId, 0, 250),
            'PS_SUM' => (float)Money::format($gatewayOrder['amount'] ?? 0),
            'PS_CURRENCY' => $currency,
            'PS_RESPONSE_DATE' => new DateTime(),
        ];
    }

    private function order(Payment $payment): Order
    {
        /** @var Order $order */
        $order = $payment->getCollection()->getOrder();

        return $order;
    }

    private function notifyUrl(Config $cfg, Order $order): string
    {
        if ($cfg->notifyUrl === '') {
            return $this->defaultNotifyUrl($order);
        }

        $query = [];
        parse_str((string)parse_url($cfg->notifyUrl, PHP_URL_QUERY), $query);
        if (parse_url($cfg->notifyUrl, PHP_URL_PATH) !== self::NOTIFY_PATH
            || (int)($query['ps'] ?? 0) !== (int)$this->service->getField('ID')) {
            throw new \InvalidArgumentException('Notification URL must be https://<site>' . self::NOTIFY_PATH . '?ps=' . (int)$this->service->getField('ID'));
        }

        return $cfg->notifyUrl;
    }

    private function defaultNotifyUrl(Order $order): string
    {
        $host = '';
        $siteId = (string)$order->getSiteId();
        if ($siteId !== '') {
            $site = SiteTable::getList(['filter' => ['=LID' => $siteId], 'select' => ['SERVER_NAME']])->fetch();
            $host = trim((string)($site['SERVER_NAME'] ?? ''));
        }
        if ($host === '') {
            $host = trim((string)Option::get('main', 'server_name', ''));
        }
        if ($host === '' || !preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/i', $host)) {
            throw new \InvalidArgumentException('Site server name is not set: fill "Server URL" in site settings or the notification URL field');
        }

        return 'https://' . $host . self::NOTIFY_PATH . '?ps=' . (int)$this->service->getField('ID');
    }

    private function payerEmail(Order $order): string
    {
        $property = $order->getPropertyCollection()->getUserEmail();
        $email = $property ? trim((string)$property->getValue()) : '';

        if ($email === '' && (int)$order->getUserId() > 0) {
            $user = UserTable::getList([
                'filter' => ['=ID' => (int)$order->getUserId()],
                'select' => ['EMAIL'],
            ])->fetch();
            $email = trim((string)($user['EMAIL'] ?? ''));
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function payerProperty(Order $order, string $getter): string
    {
        $property = $order->getPropertyCollection()->{$getter}();

        return $property ? mb_substr(trim((string)$property->getValue()), 0, 100) : '';
    }

    /**
     * @return list<array{name: string, count: float, price: float}>
     */
    private function basketItems(Order $order): array
    {
        $basket = $order->getBasket();
        if (!$basket) {
            return [];
        }

        $items = [];
        foreach ($basket->getBasketItems() as $item) {
            $items[] = [
                'name' => (string)$item->getField('NAME'),
                'count' => (float)$item->getQuantity(),
                'price' => (float)$item->getPrice(),
            ];
        }

        $delivery = (float)$order->getDeliveryPrice();
        if ($delivery > 0) {
            $items[] = [
                'name' => (string)Loc::getMessage('SMOSERVICE_ROSKASSA_RECEIPT_DELIVERY'),
                'count' => 1.0,
                'price' => $delivery,
            ];
        }

        return $items;
    }

    private function fail(PaySystem\ServiceResult $result, string $message): PaySystem\ServiceResult
    {
        PaySystem\Logger::addError('Roskassa: ' . $message);
        $result->addError(new Error($message));

        return $result;
    }

    /**
     * Сырые POST-данные: подпись считается по тому, что прислал шлюз, до фильтров
     * проактивной защиты Bitrix. Query-параметры (?ps=) в подпись не входят.
     *
     * @return array<string, mixed>
     */
    public static function postData(Request $request): array
    {
        $post = $request->getPostList();
        $raw = $post->toArrayRaw();

        return is_array($raw) ? $raw : $post->toArray();
    }
}
