<?php

namespace Smoservice\Roskassa;

use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * Журнал проведённых оплат шлюза. Первичный ключ — id заказа Roskassa:
 * одна оплата шлюза проводится на сайте не больше одного раза, что бы ни случилось
 * потом с платежом (снятие оплаты админом, возврат на счёт, частично упавшее сохранение).
 *
 * Статусы: credited — проведена; mismatch — оплачена у шлюза, но не сходится с платежом;
 * duplicate — повторная оплата уже оплаченного платежа (вернуть покупателю);
 * review — оплачена, но автоматически не проведена (заказ отменён, платёж уже снимали с оплаты
 *          или перевели на другую платёжную систему);
 * save_failed — оплата застолблена, но сайт не смог сохранить платёж оплаченным;
 * orphan — оплачена у шлюза, а платежа с таким номером на сайте нет.
 * Всё, кроме credited, — на ручной разбор; запись не даёт повторять предупреждение каждый запуск.
 */
final class CreditTable extends DataManager
{
    public const STATUS_CREDITED = 'credited';
    public const STATUS_MISMATCH = 'mismatch';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_REVIEW = 'review';
    public const STATUS_SAVE_FAILED = 'save_failed';
    public const STATUS_ORPHAN = 'orphan';

    public static function getTableName()
    {
        return 'smoservice_roskassa_credit';
    }

    public static function getMap()
    {
        // длину колонки в DDL задаёт только LengthValidator: без него varchar(255),
        // а такой первичный ключ в utf8mb4 не влезает в лимит индекса старых MySQL
        return [
            (new StringField('GATEWAY_ORDER_ID'))->configurePrimary()->addValidator(new LengthValidator(1, 64)),
            (new IntegerField('PAYMENT_ID'))->configureRequired(),
            (new StringField('STATUS'))->configureRequired()->addValidator(new LengthValidator(1, 16)),
            (new StringField('AMOUNT'))->addValidator(new LengthValidator(null, 32)),
            (new StringField('CURRENCY'))->addValidator(new LengthValidator(null, 3)),
            (new DatetimeField('CREATED_AT'))->configureRequired(),
        ];
    }

    /**
     * Застолбить оплату шлюза. false — эта оплата уже есть в журнале (проведена или разобрана).
     * Любая другая ошибка БД пробрасывается: «не смогли записать» ≠ «уже проведено».
     *
     * @throws SqlQueryException
     */
    public static function claim(string $gatewayOrderId, int $paymentId, string $status, string $amount, string $currency): bool
    {
        if ($gatewayOrderId === '' || strlen($gatewayOrderId) > 64) {
            // «не записали» не должно выглядеть как «уже в журнале»
            throw new \InvalidArgumentException('Gateway order id is empty or longer than 64 characters');
        }
        if (static::find($gatewayOrderId) !== null) {
            return false;
        }

        try {
            $result = static::add([
                'GATEWAY_ORDER_ID' => $gatewayOrderId,
                'PAYMENT_ID' => $paymentId,
                'STATUS' => $status,
                'AMOUNT' => mb_substr($amount, 0, 32),
                'CURRENCY' => mb_substr($currency, 0, 3),
                'CREATED_AT' => new DateTime(),
            ]);
        } catch (SqlQueryException $e) {
            if (static::find($gatewayOrderId) !== null) {
                return false; // параллельный процесс успел раньше
            }
            throw $e;
        }

        if (!$result->isSuccess()) {
            throw new SqlQueryException('Cannot write payment journal: ' . implode('; ', $result->getErrorMessages()));
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $gatewayOrderId): ?array
    {
        $row = static::getByPrimary($gatewayOrderId)->fetch();

        return is_array($row) ? $row : null;
    }

    public static function setStatus(string $gatewayOrderId, string $status): void
    {
        $result = static::update($gatewayOrderId, ['STATUS' => $status]);
        if (!$result->isSuccess()) {
            throw new SqlQueryException('Cannot update payment journal: ' . implode('; ', $result->getErrorMessages()));
        }
    }
}
