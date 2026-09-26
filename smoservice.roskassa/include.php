<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('smoservice.roskassa', [
    'Smoservice\\Roskassa\\ApiClient' => 'lib/ApiClient.php',
    'Smoservice\\Roskassa\\ApiException' => 'lib/ApiException.php',
    'Smoservice\\Roskassa\\BaseUrl' => 'lib/BaseUrl.php',
    'Smoservice\\Roskassa\\Config' => 'lib/Config.php',
    'Smoservice\\Roskassa\\ConfigLoader' => 'lib/ConfigLoader.php',
    'Smoservice\\Roskassa\\CreditTable' => 'lib/CreditTable.php',
    'Smoservice\\Roskassa\\Handler' => 'lib/Handler.php',
    'Smoservice\\Roskassa\\Money' => 'lib/Money.php',
    'Smoservice\\Roskassa\\NotifyController' => 'lib/NotifyController.php',
    'Smoservice\\Roskassa\\NotifyValidator' => 'lib/NotifyValidator.php',
    'Smoservice\\Roskassa\\OrderVerifier' => 'lib/OrderVerifier.php',
    'Smoservice\\Roskassa\\PaymentForm' => 'lib/PaymentForm.php',
    'Smoservice\\Roskassa\\PaymentLock' => 'lib/PaymentLock.php',
    'Smoservice\\Roskassa\\Reconciler' => 'lib/Reconciler.php',
    'Smoservice\\Roskassa\\Signature' => 'lib/Signature.php',
]);
