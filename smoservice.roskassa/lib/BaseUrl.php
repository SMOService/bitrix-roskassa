<?php

namespace Smoservice\Roskassa;

/**
 * Адрес платёжного шлюза. Вынесен в настройки, чтобы при смене домена-зеркала
 * хватило поправить одно поле в админке, без выката кода.
 */
final class BaseUrl
{
    public const DEFAULT = 'https://roskassa.net';

    public static function normalize(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return self::DEFAULT;
        }

        if (!preg_match('~^https://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:\d{1,5})?$~i', $url)) {
            throw new \InvalidArgumentException('Gateway URL must look like https://example.com');
        }

        return strtolower($url);
    }
}
