<?php

$MESS['SMOSERVICE_ROSKASSA_TITLE'] = 'Roskassa';
$MESS['SMOSERVICE_ROSKASSA_DESCRIPTION'] = 'Accept payments via Roskassa (roskassa.net). Every payment is confirmed through the gateway API; lost notifications are picked up by the reconciliation agent.';
$MESS['SMOSERVICE_ROSKASSA_GROUP_SHOP'] = 'Roskassa shop';
$MESS['SMOSERVICE_ROSKASSA_GROUP_FORM'] = 'Payment form';
$MESS['SMOSERVICE_ROSKASSA_SHOP_ID'] = 'Shop ID (shop_id)';
$MESS['SMOSERVICE_ROSKASSA_SHOP_ID_DESCR'] = 'Public shop key from the Roskassa dashboard';
$MESS['SMOSERVICE_ROSKASSA_SECRET_KEY'] = 'Secret key';
$MESS['SMOSERVICE_ROSKASSA_SECRET_KEY_DESCR'] = 'Signs the payment form and notifications. Shop settings in the Roskassa dashboard';
$MESS['SMOSERVICE_ROSKASSA_API_KEY'] = 'API key';
$MESS['SMOSERVICE_ROSKASSA_API_KEY_DESCR'] = 'Required: payments are not accepted without it. Generate it in the shop settings in the Roskassa dashboard';
$MESS['SMOSERVICE_ROSKASSA_BASE_URL'] = 'Gateway URL';
$MESS['SMOSERVICE_ROSKASSA_BASE_URL_DESCR'] = 'Defaults to https://roskassa.net. Change only if the gateway moved to another domain';
$MESS['SMOSERVICE_ROSKASSA_TEST_MODE'] = 'Test mode';
$MESS['SMOSERVICE_ROSKASSA_TEST_MODE_DESCR'] = 'Test payments are accepted only in this mode and only for orders of site administrators. Keep it off on a live site';
$MESS['SMOSERVICE_ROSKASSA_PAYMENT_SYSTEM'] = 'Payment method ID';
$MESS['SMOSERVICE_ROSKASSA_PAYMENT_SYSTEM_DESCR'] = 'Optional. If set, the buyer goes straight to this method (card, SBP, etc.) without the selection screen';
$MESS['SMOSERVICE_ROSKASSA_LANG'] = 'Payment page language';
$MESS['SMOSERVICE_ROSKASSA_LANG_AUTO'] = 'Gateway default';
$MESS['SMOSERVICE_ROSKASSA_LANG_RU'] = 'Russian';
$MESS['SMOSERVICE_ROSKASSA_LANG_EN'] = 'English';
$MESS['SMOSERVICE_ROSKASSA_SUCCESS_URL'] = 'Success page';
$MESS['SMOSERVICE_ROSKASSA_FAIL_URL'] = 'Failure page';
$MESS['SMOSERVICE_ROSKASSA_URL_DESCR'] = 'Optional. Absolute URL with https://. Empty — taken from the shop settings in the dashboard';
$MESS['SMOSERVICE_ROSKASSA_NOTIFY_URL'] = 'Notification URL';
$MESS['SMOSERVICE_ROSKASSA_NOTIFY_URL_DESCR'] = 'Usually left empty: the module sends https://your-site/bitrix/tools/smoservice_roskassa_notify.php?ps=ID itself';
$MESS['SMOSERVICE_ROSKASSA_ORDER_PREFIX'] = 'Order number prefix';
$MESS['SMOSERVICE_ROSKASSA_ORDER_PREFIX_DESCR'] = 'Order number at the gateway: "prefix-payment ID". Empty — a random per-site prefix generated at install (so sites sharing one Roskassa shop never accept each other\'s payments). Do not change after launch: reconciliation will not find payments with the old prefix';
