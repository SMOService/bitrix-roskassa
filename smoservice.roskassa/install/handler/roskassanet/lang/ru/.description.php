<?php

$MESS['SMOSERVICE_ROSKASSA_TITLE'] = 'Roskassa';
$MESS['SMOSERVICE_ROSKASSA_DESCRIPTION'] = 'Приём оплаты через Roskassa (roskassa.net). Каждая оплата подтверждается запросом к API шлюза; потерянные уведомления находит агент сверки.';
$MESS['SMOSERVICE_ROSKASSA_GROUP_SHOP'] = 'Магазин Roskassa';
$MESS['SMOSERVICE_ROSKASSA_GROUP_FORM'] = 'Форма оплаты';
$MESS['SMOSERVICE_ROSKASSA_SHOP_ID'] = 'ID магазина (shop_id)';
$MESS['SMOSERVICE_ROSKASSA_SHOP_ID_DESCR'] = 'Публичный ключ магазина из кабинета Roskassa';
$MESS['SMOSERVICE_ROSKASSA_SECRET_KEY'] = 'Секретный ключ';
$MESS['SMOSERVICE_ROSKASSA_SECRET_KEY_DESCR'] = 'Подписывает форму оплаты и уведомления. Настройки магазина в кабинете Roskassa';
$MESS['SMOSERVICE_ROSKASSA_API_KEY'] = 'API-ключ';
$MESS['SMOSERVICE_ROSKASSA_API_KEY_DESCR'] = 'Обязателен: без него оплата не будет засчитана. Генерируется в настройках магазина в кабинете Roskassa';
$MESS['SMOSERVICE_ROSKASSA_BASE_URL'] = 'Адрес шлюза';
$MESS['SMOSERVICE_ROSKASSA_BASE_URL_DESCR'] = 'По умолчанию https://roskassa.net. Меняйте, только если шлюз переехал на другой домен';
$MESS['SMOSERVICE_ROSKASSA_TEST_MODE'] = 'Тестовый режим';
$MESS['SMOSERVICE_ROSKASSA_TEST_MODE_DESCR'] = 'Тестовые платежи засчитываются только в этом режиме и только по заказам администраторов сайта. На боевом сайте — выключен';
$MESS['SMOSERVICE_ROSKASSA_PAYMENT_SYSTEM'] = 'ID способа оплаты';
$MESS['SMOSERVICE_ROSKASSA_PAYMENT_SYSTEM_DESCR'] = 'Необязательно. Если задан, покупатель сразу попадает на этот способ (карта, СБП и т.д.), без экрана выбора';
$MESS['SMOSERVICE_ROSKASSA_LANG'] = 'Язык страницы оплаты';
$MESS['SMOSERVICE_ROSKASSA_LANG_AUTO'] = 'Как в настройках шлюза';
$MESS['SMOSERVICE_ROSKASSA_LANG_RU'] = 'Русский';
$MESS['SMOSERVICE_ROSKASSA_LANG_EN'] = 'Английский';
$MESS['SMOSERVICE_ROSKASSA_SUCCESS_URL'] = 'Страница успешной оплаты';
$MESS['SMOSERVICE_ROSKASSA_FAIL_URL'] = 'Страница неудачной оплаты';
$MESS['SMOSERVICE_ROSKASSA_URL_DESCR'] = 'Необязательно. Полный адрес с https://. Если пусто — берётся из настроек магазина в кабинете';
$MESS['SMOSERVICE_ROSKASSA_NOTIFY_URL'] = 'Адрес уведомлений';
$MESS['SMOSERVICE_ROSKASSA_NOTIFY_URL_DESCR'] = 'Обычно оставляют пустым: модуль сам передаёт https://ваш-сайт/bitrix/tools/smoservice_roskassa_notify.php?ps=ID';
$MESS['SMOSERVICE_ROSKASSA_ORDER_PREFIX'] = 'Префикс номера заказа';
$MESS['SMOSERVICE_ROSKASSA_ORDER_PREFIX_DESCR'] = 'Номер заказа у шлюза: «префикс-ID платежа». Пусто — случайный префикс этого сайта, созданный при установке модуля (так сайты на одном магазине Roskassa не примут оплаты друг друга). После запуска не меняйте: оплаты со старым префиксом сверка не найдёт';
