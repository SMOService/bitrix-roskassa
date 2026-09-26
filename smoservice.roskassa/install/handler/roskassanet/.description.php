<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\PaySystem;

Loc::loadMessages(__FILE__);

$isAvailable = PaySystem\Manager::HANDLER_AVAILABLE_TRUE;

$group = Loc::getMessage('SMOSERVICE_ROSKASSA_GROUP_SHOP');
$groupForm = Loc::getMessage('SMOSERVICE_ROSKASSA_GROUP_FORM');

$yesNo = [
    'TYPE' => 'Y/N',
];

$data = [
    'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_TITLE'),
    'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_DESCRIPTION'),
    'SORT' => 500,
    'CODES' => [
        'ROSKASSA_SHOP_ID' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_SHOP_ID'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_SHOP_ID_DESCR'),
            'SORT' => 100,
            'GROUP' => $group,
        ],
        'ROSKASSA_SECRET_KEY' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_SECRET_KEY'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_SECRET_KEY_DESCR'),
            'SORT' => 200,
            'GROUP' => $group,
        ],
        'ROSKASSA_API_KEY' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_API_KEY'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_API_KEY_DESCR'),
            'SORT' => 300,
            'GROUP' => $group,
        ],
        'ROSKASSA_BASE_URL' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_BASE_URL'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_BASE_URL_DESCR'),
            'SORT' => 400,
            'GROUP' => $group,
            'DEFAULT' => [
                'PROVIDER_KEY' => 'VALUE',
                'PROVIDER_VALUE' => 'https://roskassa.net',
            ],
        ],
        'ROSKASSA_ORDER_PREFIX' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_ORDER_PREFIX'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_ORDER_PREFIX_DESCR'),
            'SORT' => 450,
            'GROUP' => $group,
        ],
        'ROSKASSA_TEST_MODE' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_TEST_MODE'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_TEST_MODE_DESCR'),
            'SORT' => 500,
            'GROUP' => $group,
            'INPUT' => $yesNo,
            'DEFAULT' => [
                'PROVIDER_KEY' => 'INPUT',
                'PROVIDER_VALUE' => 'N',
            ],
        ],
        'ROSKASSA_PAYMENT_SYSTEM' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_PAYMENT_SYSTEM'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_PAYMENT_SYSTEM_DESCR'),
            'SORT' => 600,
            'GROUP' => $groupForm,
        ],
        'ROSKASSA_LANG' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_LANG'),
            'SORT' => 700,
            'GROUP' => $groupForm,
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => [
                    '' => Loc::getMessage('SMOSERVICE_ROSKASSA_LANG_AUTO'),
                    'ru' => Loc::getMessage('SMOSERVICE_ROSKASSA_LANG_RU'),
                    'en' => Loc::getMessage('SMOSERVICE_ROSKASSA_LANG_EN'),
                ],
            ],
        ],
        'ROSKASSA_SUCCESS_URL' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_SUCCESS_URL'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_URL_DESCR'),
            'SORT' => 800,
            'GROUP' => $groupForm,
        ],
        'ROSKASSA_FAIL_URL' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_FAIL_URL'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_URL_DESCR'),
            'SORT' => 900,
            'GROUP' => $groupForm,
        ],
        'ROSKASSA_NOTIFY_URL' => [
            'NAME' => Loc::getMessage('SMOSERVICE_ROSKASSA_NOTIFY_URL'),
            'DESCRIPTION' => Loc::getMessage('SMOSERVICE_ROSKASSA_NOTIFY_URL_DESCR'),
            'SORT' => 1000,
            'GROUP' => $groupForm,
        ],
    ],
];
