<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class smoservice_roskassa extends CModule
{
    public $MODULE_ID = 'smoservice.roskassa';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    private const HANDLER = 'roskassanet';
    private const NOTIFY_FILE = 'smoservice_roskassa_notify.php';
    private const AGENT = '\\Smoservice\\Roskassa\\Reconciler::agent();';
    private const AGENT_INTERVAL = 600;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('SMOSERVICE_ROSKASSA_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('SMOSERVICE_ROSKASSA_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = 'SMOService';
        $this->PARTNER_URI = 'https://github.com/SMOService/bitrix-roskassa';
    }

    public function DoInstall()
    {
        global $APPLICATION;

        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            $APPLICATION->ThrowException(Loc::getMessage('SMOSERVICE_ROSKASSA_ERR_PHP'));

            return false;
        }
        if (!ModuleManager::isModuleInstalled('sale')) {
            $APPLICATION->ThrowException(Loc::getMessage('SMOSERVICE_ROSKASSA_ERR_SALE'));

            return false;
        }

        try {
            $this->InstallDB();
        } catch (\Throwable $e) {
            $APPLICATION->ThrowException(Loc::getMessage('SMOSERVICE_ROSKASSA_ERR_DB') . ' ' . $e->getMessage());

            return false;
        }

        try {
            $this->InstallFiles();
        } catch (\RuntimeException $e) {
            $APPLICATION->ThrowException($e->getMessage());

            return false;
        }
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallDefaults();
        try {
            $this->InstallAgents();
        } catch (\RuntimeException $e) {
            ModuleManager::unRegisterModule($this->MODULE_ID);
            $this->UnInstallFiles();
            $APPLICATION->ThrowException($e->getMessage());

            return false;
        }

        return true;
    }

    public function DoUninstall()
    {
        global $APPLICATION;

        if (Loader::includeModule('sale')) {
            $inUse = \Bitrix\Sale\PaySystem\Manager::getList([
                'filter' => ['=ACTION_FILE' => self::HANDLER],
                'select' => ['ID'],
                'limit' => 1,
            ])->fetch();
            if ($inUse) {
                $APPLICATION->ThrowException(Loc::getMessage('SMOSERVICE_ROSKASSA_ERR_IN_USE'));

                return false;
            }
        }

        $this->UnInstallAgents();
        ModuleManager::unRegisterModule($this->MODULE_ID);
        $this->UnInstallFiles();

        return true;
    }

    /**
     * Журнал проведённых оплат. При удалении модуля не удаляется: это денежный аудит.
     */
    public function InstallDB()
    {
        require_once __DIR__ . '/../lib/CreditTable.php';

        $connection = Application::getConnection();
        if (!$connection->isTableExists(\Smoservice\Roskassa\CreditTable::getTableName())) {
            \Smoservice\Roskassa\CreditTable::getEntity()->createDbTable();
        }

        return true;
    }

    /**
     * Префикс номеров заказов по умолчанию — случайный для каждого сайта: два сайта на одном
     * магазине Roskassa с одинаковым префиксом принимали бы оплаты друг друга.
     */
    public function InstallDefaults()
    {
        if ((string)Option::get($this->MODULE_ID, 'default_order_prefix', '') === '') {
            Option::set($this->MODULE_ID, 'default_order_prefix', 'r' . bin2hex(random_bytes(4)));
        }
        // раньше этой даты заказов шлюза с префиксом сайта быть не может — граница полного обхода сверки
        if ((int)Option::get($this->MODULE_ID, 'installed_at', 0) === 0) {
            Option::set($this->MODULE_ID, 'installed_at', (string)time());
        }

        return true;
    }

    public function InstallFiles()
    {
        $root = Application::getDocumentRoot();

        $handlerDir = $root . self::handlersDir() . self::HANDLER;
        $copied = CopyDirFiles(__DIR__ . '/handler/' . self::HANDLER, $handlerDir, true, true)
            && CopyDirFiles(__DIR__ . '/tools', $root . '/bitrix/tools', true, true);

        foreach ([$handlerDir . '/handler.php', $handlerDir . '/.description.php', $root . '/bitrix/tools/' . self::NOTIFY_FILE] as $file) {
            if (!is_file($file)) {
                $copied = false;
            }
        }
        if (!$copied) {
            $this->UnInstallFiles();
            throw new \RuntimeException(Loc::getMessage('SMOSERVICE_ROSKASSA_ERR_FILES'));
        }

        return true;
    }

    public function UnInstallFiles()
    {
        $root = Application::getDocumentRoot();

        Directory::deleteDirectory($root . self::handlersDir() . self::HANDLER);
        File::deleteFile($root . '/bitrix/tools/' . self::NOTIFY_FILE);

        return true;
    }

    public function InstallAgents()
    {
        $exists = \CAgent::GetList([], ['NAME' => self::AGENT, 'MODULE_ID' => $this->MODULE_ID])->Fetch();
        if ($exists) {
            if ($exists['ACTIVE'] !== 'Y' && !\CAgent::Update($exists['ID'], ['ACTIVE' => 'Y'])) {
                throw new \RuntimeException(Loc::getMessage('SMOSERVICE_ROSKASSA_ERR_AGENT'));
            }

            return true;
        }

        // без сверки потерянное уведомление = потерянная оплата: установка без агента не считается успешной
        if (!\CAgent::AddAgent(self::AGENT, $this->MODULE_ID, 'N', self::AGENT_INTERVAL)) {
            throw new \RuntimeException(Loc::getMessage('SMOSERVICE_ROSKASSA_ERR_AGENT'));
        }

        return true;
    }

    public function UnInstallAgents()
    {
        \CAgent::RemoveModuleAgents($this->MODULE_ID);

        return true;
    }

    /**
     * Папка пользовательских обработчиков модуля sale (настраивается в sale, по умолчанию
     * /bitrix/php_interface/include/sale_payment/).
     */
    private static function handlersDir(): string
    {
        $dir = (string)Option::get('sale', 'path2user_ps_files', BX_PERSONAL_ROOT . '/php_interface/include/sale_payment/');

        return '/' . trim($dir, '/') . '/';
    }
}
