<?php

$MESS['SMOSERVICE_ROSKASSA_MODULE_NAME'] = 'Roskassa payments';
$MESS['SMOSERVICE_ROSKASSA_MODULE_DESCRIPTION'] = 'Roskassa (roskassa.net) payment system for the online store: every payment confirmed through the API, lost notifications reconciled automatically.';
$MESS['SMOSERVICE_ROSKASSA_ERR_PHP'] = 'PHP 7.4 or newer is required.';
$MESS['SMOSERVICE_ROSKASSA_ERR_SALE'] = 'Install the Online Store (sale) module first.';
$MESS['SMOSERVICE_ROSKASSA_ERR_IN_USE'] = 'Delete the payment systems that use the Roskassa handler first (Store → Settings → Payment systems). The payment journal table smoservice_roskassa_credit is kept when the module is removed.';
$MESS['SMOSERVICE_ROSKASSA_ERR_DB'] = 'Could not create the payment journal table smoservice_roskassa_credit:';
$MESS['SMOSERVICE_ROSKASSA_ERR_AGENT'] = 'Could not register the reconciliation agent \\Smoservice\\Roskassa\\Reconciler::agent(). Without it lost payment notifications are not recovered.';
$MESS['SMOSERVICE_ROSKASSA_ERR_FILES'] = 'Could not copy the handler or notification endpoint files. Check write permissions for the sale handlers folder and /bitrix/tools.';
