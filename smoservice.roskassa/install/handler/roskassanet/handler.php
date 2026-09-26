<?php

namespace Sale\Handlers\PaySystem;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

if (!Loader::includeModule('smoservice.roskassa')) {
    throw new SystemException('Module smoservice.roskassa is not installed');
}

/**
 * Обёртка: вся логика — в модуле smoservice.roskassa (обновляется вместе с ним).
 */
class RoskassanetHandler extends \Smoservice\Roskassa\Handler
{
}
