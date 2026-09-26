<?php

$MESS['SMOSERVICE_ROSKASSA_MODULE_NAME'] = 'Roskassa — приём платежей';
$MESS['SMOSERVICE_ROSKASSA_MODULE_DESCRIPTION'] = 'Платёжная система Roskassa (roskassa.net) для интернет-магазина: подтверждение каждой оплаты через API и автоматическая сверка потерянных уведомлений.';
$MESS['SMOSERVICE_ROSKASSA_ERR_PHP'] = 'Нужен PHP 7.4 или новее.';
$MESS['SMOSERVICE_ROSKASSA_ERR_SALE'] = 'Сначала установите модуль «Интернет-магазин» (sale).';
$MESS['SMOSERVICE_ROSKASSA_ERR_IN_USE'] = 'Сначала удалите платёжные системы на обработчике Roskassa (Магазин → Настройки → Платёжные системы). Журнал проведённых оплат (таблица smoservice_roskassa_credit) при удалении модуля сохраняется.';
$MESS['SMOSERVICE_ROSKASSA_ERR_DB'] = 'Не удалось создать таблицу журнала оплат smoservice_roskassa_credit:';
$MESS['SMOSERVICE_ROSKASSA_ERR_AGENT'] = 'Не удалось зарегистрировать агент сверки \\Smoservice\\Roskassa\\Reconciler::agent(). Без него потерянные уведомления об оплате не восстанавливаются.';
$MESS['SMOSERVICE_ROSKASSA_ERR_FILES'] = 'Не удалось скопировать файлы обработчика или адреса уведомлений. Проверьте права записи в папку обработчиков sale и в /bitrix/tools.';
