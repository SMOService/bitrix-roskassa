<?php

namespace Smoservice\Roskassa;

/**
 * Настройки платёжной системы из бизнес-значений. Не зависит от Bitrix:
 * значения приходят через функцию-читатель (код → строка).
 */
final class Config
{
    public const DEFAULT_ORDER_PREFIX = 'bx';

    /** @var string */
    public $baseUrl;
    /** @var string */
    public $shopId;
    /** @var string */
    public $secret;
    /** @var string */
    public $apiKey;
    /** @var bool */
    public $testMode;
    /** @var string */
    public $lang;
    /** @var string */
    public $paymentSystem;
    /** @var string */
    public $notifyUrl;
    /** @var string */
    public $successUrl;
    /** @var string */
    public $failUrl;
    /** @var string */
    public $orderPrefix;

    /**
     * @param callable(string): mixed $get
     * @param string $defaultPrefix префикс, если в настройках пусто (установщик генерирует случайный на сайт)
     * @throws \InvalidArgumentException
     */
    public static function fromReader(callable $get, string $defaultPrefix = self::DEFAULT_ORDER_PREFIX): self
    {
        $read = static function (string $code) use ($get): string {
            return trim((string)$get($code));
        };

        $cfg = new self();
        $cfg->baseUrl = BaseUrl::normalize($read('ROSKASSA_BASE_URL'));
        $cfg->shopId = $read('ROSKASSA_SHOP_ID');
        $cfg->secret = $read('ROSKASSA_SECRET_KEY');
        $cfg->apiKey = $read('ROSKASSA_API_KEY');
        $cfg->testMode = $read('ROSKASSA_TEST_MODE') === 'Y';
        $cfg->lang = $read('ROSKASSA_LANG');
        $cfg->paymentSystem = $read('ROSKASSA_PAYMENT_SYSTEM');
        $cfg->notifyUrl = $read('ROSKASSA_NOTIFY_URL');
        $cfg->successUrl = $read('ROSKASSA_SUCCESS_URL');
        $cfg->failUrl = $read('ROSKASSA_FAIL_URL');
        $cfg->orderPrefix = $read('ROSKASSA_ORDER_PREFIX');
        if ($cfg->orderPrefix === '') {
            $cfg->orderPrefix = $defaultPrefix;
        }

        if ($cfg->shopId === '' || $cfg->secret === '') {
            throw new \InvalidArgumentException('Shop ID and secret key are required');
        }
        if ($cfg->apiKey === '') {
            // без ключа оплату нельзя подтвердить: покупатель заплатит, а зачислить будет нечем
            throw new \InvalidArgumentException('API key is required');
        }
        if (!preg_match('/^[A-Za-z0-9]{1,16}$/', $cfg->orderPrefix)) {
            throw new \InvalidArgumentException('Order prefix must be 1-16 latin letters or digits');
        }
        if ($cfg->paymentSystem !== '' && !ctype_digit($cfg->paymentSystem)) {
            throw new \InvalidArgumentException('Payment system ID must be a number');
        }
        foreach (['notifyUrl' => $cfg->notifyUrl, 'successUrl' => $cfg->successUrl, 'failUrl' => $cfg->failUrl] as $name => $url) {
            if ($url !== '' && !preg_match('~^https?://[^\s"\'<>]+$~i', $url)) {
                throw new \InvalidArgumentException($name . ' must be an absolute URL');
            }
        }

        return $cfg;
    }

    /**
     * Ключ группировки: платёжные системы с одним магазином сверяются одним проходом по API.
     */
    public function apiFingerprint(): string
    {
        return hash('sha256', $this->baseUrl . "\n" . $this->shopId . "\n" . $this->apiKey . "\n" . $this->orderPrefix);
    }

    /**
     * Номер заказа для шлюза: «префикс-ID платежа». Префикс отделяет наши платежи от других
     * интеграций и сайтов на том же магазине Roskassa (у них свои номера, и голые числа совпадут).
     */
    public function gatewayOrderId(int $paymentId): string
    {
        return $this->orderPrefix . '-' . $paymentId;
    }

    /**
     * ID платежа из номера заказа шлюза. Префикс здесь не проверяется — это делает сверка
     * с gatewayOrderId() после загрузки настроек платёжной системы.
     */
    /**
     * ID платежа, если номер заказа шлюза выписан с префиксом этих настроек.
     */
    public function ownPaymentId(string $gatewayOrderId): ?int
    {
        $id = self::parsePaymentId($gatewayOrderId);

        return $id !== null && $gatewayOrderId === $this->gatewayOrderId($id) ? $id : null;
    }

    public static function parsePaymentId(string $gatewayOrderId): ?int
    {
        if (!preg_match('/^[A-Za-z0-9]{1,16}-([1-9][0-9]{0,17})$/', $gatewayOrderId, $m)) {
            return null;
        }

        return (int)$m[1];
    }

    public function apiClient(): ?ApiClient
    {
        return $this->apiKey === '' ? null : new ApiClient($this->baseUrl, $this->shopId, $this->apiKey);
    }
}
