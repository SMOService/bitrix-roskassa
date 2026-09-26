<?php

namespace Smoservice\Roskassa;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Internals\PaymentTable;
use Bitrix\Sale\Internals\PersonTypeTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Registry;

/**
 * Агент сверки: идёт от шлюза. Шлюз шлёт уведомление один раз; если оно потерялось
 * (сайт лежал, CDN отбил, покупатель подменил notify_url, заказ был занят) — деньги у шлюза
 * есть, а платёж на сайте не проведён.
 *
 * Для каждого магазина (и префикса номера) читаем оплаченные заказы шлюза за окно по дате шлюза,
 * берём только номера с нашим префиксом и разбираем каждый:
 *  - оплата уже в журнале: пропуск (если «credited», а платёж не оплачен — громкий сигнал);
 *  - платежа с таким номером нет — orphan;
 *  - платёж оплачен тем же заказом шлюза — дописываем журнал; другим — duplicate (вернуть);
 *  - платёж не оплачен — полная проверка и проведение.
 * Два прохода: быстрый — свежие заказы шлюза за 7 дней (по дате создания, список отсортирован по id);
 * полный — вся история с даты установки модуля по 5 страниц за запуск с сохранением места,
 * чтобы найти заказ, созданный давно и оплаченный сегодня.
 */
final class Reconciler
{
    public const AGENT = '\\Smoservice\\Roskassa\\Reconciler::agent();';

    private const WINDOW_DAYS = 7;
    private const MAX_PAGES = 30;
    private const PAGE_SIZE = 100;
    /** страниц полного обхода за запуск */
    private const DEEP_PAGES = 5;

    public static function agent(): string
    {
        try {
            // агент подключает только свой модуль; без sale не загрузятся ни платежи, ни логгер
            if (!Loader::includeModule('sale')) {
                self::log('reconcile agent: module sale is not available');

                return self::AGENT;
            }
            (new self())->run();
        } catch (\Throwable $e) {
            self::log('reconcile agent failed: ' . get_class($e) . ': ' . $e->getMessage());
        }

        return self::AGENT;
    }

    public function run(): void
    {
        $services = [];
        $list = PaySystem\Manager::getList([
            'filter' => ['=ACTION_FILE' => Handler::ACTION_FILE],
            'select' => ['*'],
        ]);
        while ($item = $list->fetch()) {
            $services[(int)$item['ID']] = $item;
        }
        if ($services === []) {
            return;
        }

        $stopBefore = (new DateTime())->add('-' . self::WINDOW_DAYS . ' days')->getTimestamp() - 86400;

        foreach (self::shops($services) as $cfg) {
            try {
                $this->reconcileShop($cfg, $services, $stopBefore);
            } catch (\Throwable $e) {
                self::log('reconcile of shop ' . $cfg->shopId . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
            }
            try {
                $this->deepPass($cfg, $services);
            } catch (\Throwable $e) {
                self::log('deep reconcile of shop ' . $cfg->shopId . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Уникальные магазины (база + магазин + ключ + префикс) по всем платёжным системам и типам плательщиков.
     *
     * @param array<int, array<string, mixed>> $services
     * @return list<Config>
     */
    private static function shops(array $services): array
    {
        $personTypes = [0];
        $rows = PersonTypeTable::getList(['select' => ['ID']]);
        while ($row = $rows->fetch()) {
            $personTypes[] = (int)$row['ID'];
        }

        $shops = [];
        foreach (array_keys($services) as $psId) {
            foreach ($personTypes as $personTypeId) {
                try {
                    $cfg = ConfigLoader::forPaySystem($psId, $personTypeId);
                } catch (\InvalidArgumentException $e) {
                    self::warnOncePerDay('cfg_' . $psId . '_' . $personTypeId, 'reconcile: pay system ' . $psId . ' (person type ' . $personTypeId . ') is not configured: ' . $e->getMessage());
                    continue;
                }
                $shops[$cfg->apiFingerprint()] = $cfg;
            }
        }

        return array_values($shops);
    }

    /**
     * Быстрый проход по свежим оплатам.
     *
     * @param array<int, array<string, mixed>> $services
     * @return list<string> id заказов шлюза в порядке списка (сверху вниз), прочитанные за проход
     */
    private function reconcileShop(Config $cfg, array $services, int $stopBefore): array
    {
        $client = $cfg->apiClient();
        if ($client === null) {
            return [];
        }
        $ids = [];

        /** @var Order $orderClass */
        $orderClass = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->getOrderClassName();

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $orders = $client->listOrders($page * self::PAGE_SIZE, 1);
            foreach ($orders as $order) {
                $ids[] = (string)($order['id'] ?? '');
            }
            $oldest = $this->processPage($orders, $cfg, $services, $orderClass);

            if (count($orders) < self::PAGE_SIZE || ($oldest !== null && $oldest < $stopBefore)) {
                return $ids;
            }
        }

        // хвост не теряется: его дочитает полный обход (deepPass)
        self::log('reconcile: more than ' . (self::MAX_PAGES * self::PAGE_SIZE) . ' paid orders in the window for shop ' . $cfg->shopId . ' — the rest is left to the full pass');

        return $ids;
    }

    /**
     * Полный обход истории оплат магазина по кусочку за запуск, с сохранением места между запусками.
     * Находит оплату старого заказа шлюза (создан давно, оплачен сегодня) и хвост после долгого сбоя.
     * Граница — дата установки модуля: раньше заказов с префиксом сайта нет.
     *
     * Место храним датой создания последнего просмотренного заказа, а не смещением: новые оплаты
     * сверху сдвигают смещения, но не даты. Каждый запуск заново находит это место поиском по дате
     * (список шлюза идёт по id, то есть по времени создания, от новых к старым).
     *
     * @param array<int, array<string, mixed>> $services
     */
    private function deepPass(Config $cfg, array $services): void
    {
        $client = $cfg->apiClient();
        if ($client === null) {
            return;
        }

        $key = 'deep_date_' . substr($cfg->apiFingerprint(), 0, 16);
        [$cursorTs, $skip] = self::parseCursor((string)Option::get('smoservice.roskassa', $key, ''));
        $installedAt = (int)Option::get('smoservice.roskassa', 'installed_at', 0);
        $boundary = $installedAt > 0 ? $installedAt - 86400 : 0;

        // точное начало группы заказов с датой курсора + сколько из неё уже прочитано
        $offset = $cursorTs > 0 ? self::groupStart($client, $cursorTs) + $skip : 0;

        /** @var Order $orderClass */
        $orderClass = Registry::getInstance(Registry::REGISTRY_TYPE_ORDER)->getOrderClassName();

        $readTs = [];
        $restart = false;
        for ($page = 0; $page < self::DEEP_PAGES; $page++) {
            $orders = $client->listOrders($offset + $page * self::PAGE_SIZE, 1);
            $oldest = $this->processPage($orders, $cfg, $services, $orderClass);
            foreach ($orders as $order) {
                $t = strtotime((string)($order['date_created'] ?? ''));
                if ($t !== false) {
                    $readTs[] = $t;
                }
            }

            if (count($orders) < self::PAGE_SIZE || ($boundary > 0 && $oldest !== null && $oldest < $boundary)) {
                $restart = true; // круг пройден — заново сверху
                break;
            }
        }

        $next = self::nextCursor($cursorTs, $skip, $readTs, $restart);
        Option::set('smoservice.roskassa', $key, $next);
    }

    /**
     * Новый курсор полного обхода по датам прочитанных заказов (сверху вниз).
     * Вся порция внутри секунды курсора — двигаемся внутри группы; иначе — последняя секунда
     * и сколько её заказов уже прочитано.
     *
     * @param list<int> $readTs
     */
    public static function nextCursor(int $cursorTs, int $skip, array $readTs, bool $restart): string
    {
        if ($restart || $readTs === []) {
            return '';
        }

        $last = end($readTs);
        if ($last === $cursorTs) {
            return $cursorTs . ':' . ($skip + count($readTs));
        }

        $same = 0;
        for ($i = count($readTs) - 1; $i >= 0 && $readTs[$i] === $last; $i--) {
            $same++;
        }

        return $last . ':' . $same;
    }

    /**
     * @return array{0: int, 1: int} [дата курсора, сколько заказов этой даты уже прочитано]
     */
    public static function parseCursor(string $value): array
    {
        if (!preg_match('/^(\d+)(?::(\d+))?$/', $value, $m)) {
            return [0, 0];
        }

        return [(int)$m[1], isset($m[2]) ? (int)$m[2] : 0];
    }

    /**
     * Точная позиция первого заказа, созданного не позже $ts.
     *
     * @throws ApiException
     */
    public static function groupStart(ApiClient $client, int $ts): int
    {
        $offset = self::offsetOfDate($client, $ts);
        for ($page = 0; $page < 3; $page++) {
            $orders = $client->listOrders($offset + $page * self::PAGE_SIZE, 1);
            foreach ($orders as $i => $order) {
                $t = strtotime((string)($order['date_created'] ?? ''));
                if ($t !== false && $t <= $ts) {
                    return $offset + $page * self::PAGE_SIZE + $i;
                }
            }
            if (count($orders) < self::PAGE_SIZE) {
                break;
            }
        }

        return $offset;
    }

    /**
     * Смещение страницы, с которой начинаются заказы, созданные не позже $ts (с запасом в страницу:
     * заказы той же секунды читаются повторно — это безопасно, проведение идемпотентно).
     * Экспоненциальный и бинарный поиск по страницам: число запросов растёт как логарифм длины
     * списка и не зависит от того, сколько оплат пришло с прошлого запуска.
     *
     * @throws ApiException
     */
    public static function offsetOfDate(ApiClient $client, int $ts): int
    {
        $firstTs = static function (int $offset) use ($client): ?int {
            $orders = $client->listOrders($offset, 1);
            if ($orders === []) {
                return null;
            }
            $t = strtotime((string)($orders[0]['date_created'] ?? ''));

            return $t === false ? null : $t;
        };

        $low = 0;
        $high = self::PAGE_SIZE;
        while (true) {
            $t = $firstTs($high);
            if ($t === null || $t <= $ts) {
                break;
            }
            $low = $high;
            $high *= 2;
        }

        while ($high - $low > self::PAGE_SIZE) {
            $mid = $low + intdiv(intdiv($high - $low, self::PAGE_SIZE), 2) * self::PAGE_SIZE;
            $t = $firstTs($mid);
            if ($t === null || $t <= $ts) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        // запас в страницу: записи на стыке могут идти не строго по дате (заказы одной секунды,
        // сдвиг часов шлюза) — лучше перечитать, чем пропустить
        return max(0, $low - self::PAGE_SIZE);
    }

    /**
     * @param list<array<string, mixed>> $orders
     * @param array<int, array<string, mixed>> $services
     * @param class-string<Order>|string $orderClass
     * @return int|null самая ранняя дата создания на странице
     */
    private function processPage(array $orders, Config $cfg, array $services, $orderClass): ?int
    {
        $oldest = null;
        foreach ($orders as $gatewayOrder) {
            $created = strtotime((string)($gatewayOrder['date_created'] ?? ''));
            if ($created !== false) {
                $oldest = $oldest === null ? $created : min($oldest, $created);
            }

            $paymentId = $cfg->ownPaymentId((string)($gatewayOrder['order_id'] ?? ''));
            if ($paymentId === null || (int)($gatewayOrder['status'] ?? 0) !== 1) {
                continue; // чужая интеграция, другой сайт или не оплачено
            }

            try {
                $this->reconcileOne($gatewayOrder, $paymentId, $cfg, $services, $orderClass);
            } catch (\Throwable $e) {
                self::log('reconcile of gateway order ' . ($gatewayOrder['id'] ?? '?') . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        return $oldest;
    }

    /**
     * @param array<string, mixed> $gatewayOrder
     * @param array<int, array<string, mixed>> $services
     * @param class-string<Order>|string $orderClass
     */
    private function reconcileOne(array $gatewayOrder, int $paymentId, Config $cfg, array $services, $orderClass): void
    {
        $gatewayId = (string)($gatewayOrder['id'] ?? '');
        if ($gatewayId === '') {
            return;
        }

        $row = PaymentTable::getList([
            'filter' => ['=ID' => $paymentId],
            'select' => ['ID', 'ORDER_ID', 'PAY_SYSTEM_ID', 'PAID'],
        ])->fetch();

        $journal = CreditTable::find($gatewayId);
        if ($journal !== null) {
            if ($journal['STATUS'] === CreditTable::STATUS_CREDITED && is_array($row) && $row['PAID'] !== 'Y') {
                // перепроверяем под блокировкой: уведомление может как раз сохранять этот платёж
                PaymentLock::run((int)$row['ORDER_ID'], static function () use ($gatewayId, $paymentId) {
                    self::checkCreditedButUnpaid($gatewayId, $paymentId);

                    return null;
                });
            }

            return;
        }

        if (!is_array($row)) {
            if (CreditTable::claim($gatewayId, $paymentId, CreditTable::STATUS_ORPHAN, (string)($gatewayOrder['amount'] ?? ''), (string)($gatewayOrder['currency'] ?? ''))) {
                self::log('gateway order ' . $gatewayId . ' (' . ($gatewayOrder['order_id'] ?? '') . ') is paid, but payment ' . $paymentId . ' does not exist on the site — manual review');
            }

            return;
        }

        // платёж мог уйти на другую платёжную систему — обработчик берём по той, где он был нашим
        $item = $services[(int)$row['PAY_SYSTEM_ID']] ?? reset($services);

        PaymentLock::run((int)$row['ORDER_ID'], static function () use ($orderClass, $row, $paymentId, $item, $gatewayOrder, $cfg) {
            $order = $orderClass::load((int)$row['ORDER_ID']);
            $payment = $order ? $order->getPaymentCollection()->getItemById($paymentId) : null;
            if (!$payment) {
                return null;
            }

            return self::handler($item)->reconcileGatewayOrder($payment, $gatewayOrder, $cfg);
        });
    }

    /**
     * В журнале «проведено», а платёж не оплачен. Либо сохранение упало (громкий сигнал),
     * либо оплату потом сняли вручную или вернули — тогда это не сбой, а решение админа.
     */
    private static function checkCreditedButUnpaid(string $gatewayId, int $paymentId): void
    {
        $row = PaymentTable::getList([
            'filter' => ['=ID' => $paymentId],
            'select' => ['PAID', 'IS_RETURN', 'DATE_PAID', 'EMP_PAID_ID'],
        ])->fetch();
        if (!is_array($row) || $row['PAID'] === 'Y') {
            return;
        }

        $unpaidByAdmin = ($row['IS_RETURN'] !== '' && $row['IS_RETURN'] !== 'N')
            || $row['DATE_PAID'] !== null
            || (int)$row['EMP_PAID_ID'] > 0;
        if ($unpaidByAdmin) {
            CreditTable::setStatus($gatewayId, CreditTable::STATUS_REVIEW);
            self::log('payment ' . $paymentId . ' was credited from gateway order ' . $gatewayId . ' and later unpaid or returned on the site — not credited again, check if intended');

            return;
        }

        Handler::reportSaveFailed($gatewayId, $paymentId, 'journal says credited, payment is unpaid');
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function handler(array $item): Handler
    {
        [$class, $type] = PaySystem\Manager::includeHandler($item['ACTION_FILE']);

        return new $class($type, new PaySystem\Service($item));
    }

    private static function warnOncePerDay(string $key, string $message): void
    {
        $option = 'warned_' . $key;
        $today = date('Y-m-d');
        if (Option::get('smoservice.roskassa', $option, '') !== $today) {
            Option::set('smoservice.roskassa', $option, $today);
            self::log($message);
        }
    }

    private static function log(string $message): void
    {
        if (class_exists(PaySystem\Logger::class)) {
            PaySystem\Logger::addError('Roskassa: ' . $message);
        } else {
            // без sale остаётся только системный журнал PHP (AddMessage2Log без LOG_FILENAME молчит)
            error_log('Roskassa: ' . $message);
        }
    }
}
