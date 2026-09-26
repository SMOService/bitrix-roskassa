<?php

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (\Bitrix\Main\Loader::includeModule('sale') && \Bitrix\Main\Loader::includeModule('smoservice.roskassa')) {
    \Smoservice\Roskassa\NotifyController::handle();
} else {
    http_response_code(503);
    echo 'FAIL';
}

\CMain::FinalActions();
die();
