<?php
IncludeModuleLangFile(__FILE__);

class komtet_kassa extends CModule
{
    var $MODULE_ID = 'komtet.kassa';
    var $MODULE_NAME;
    var $MODULE_DESCRIPTION;
    var $MODULE_VERSION;
    var $MODULE_VERSION_DATE;
    private $INSTALL_DIR;

    public function __construct()
    {
        $this->MODULE_ID = "komtet.kassa";
        $this->MODULE_NAME = GetMessage('KOMTETKASSA_MODULE_NAME');
        $this->MODULE_DESCRIPTION = GetMessage('KOMTETKASSA_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = GetMessage('KOMTETKASSA_PARTNER_NAME');
        $this->PARTNER_URI = "https://kassa.komtet.ru";
        $this->INSTALL_DIR = dirname(__file__);
        $arModuleVersion = array();
        include(realpath(sprintf('%s/version.php', $this->INSTALL_DIR)));
        if (is_array($arModuleVersion) && array_key_exists("VERSION", $arModuleVersion)) {
            $this->MODULE_VERSION = $arModuleVersion["VERSION"];
            $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
        }
    }

    public function DoInstall()
    {
        if (!$this->DoInstallDB()) {
            return false;
        }
        $this->DoInstallFiles();
        COption::SetOptionString($this->MODULE_ID, 'server_url', 'https://kassa.komtet.ru');
        COption::SetOptionInt($this->MODULE_ID, 'should_print', 1);
        RegisterModule($this->MODULE_ID);
        RegisterModuleDependences('sale', 'OnSalePayOrder', $this->MODULE_ID, 'KomtetKassa', 'handleSalePayOrder');
        return true;
    }

    public function DoInstallDB()
    {
        global $DB, $DBType, $APPLICATION;
        $errors = $DB->RunSQLBatch(sprintf('%s/db/%s/install.sql', $this->INSTALL_DIR, $DBType));
        if (empty($errors)) {
            return true;
        }
        $APPLICATION->ThrowException(implode('', $errors));
        return false;
    }

    public function DoInstallFiles()
    {
        foreach (array('admin', 'tools') as $key) {
            CopyDirFiles(
                sprintf('%s/%s', $this->INSTALL_DIR, $key),
                sprintf('%s/bitrix/%s', $_SERVER["DOCUMENT_ROOT"], $key),
                true,
                true
            );
        }
    }

    public function DoUninstall()
    {
        COption::RemoveOption($this->MODULE_ID);
        UnRegisterModuleDependences("sale", "OnSalePayOrder", $this->MODULE_ID, "KomtetKassa", "handleSalePayOrder");
        UnRegisterModule($this->MODULE_ID);
        $this->DoUninstallDB();
        $this->DoUninstallFiles();
        return true;
    }

    public function DoUninstallDB()
    {
        global $DB, $DBType;
        $DB->RunSQLBatch(sprintf('%s/db/%s/uninstall.sql', $this->INSTALL_DIR, $DBType));
    }

    public function DoUninstallFiles()
    {
        foreach (array('admin', 'tools') as $key) {
            DeleteDirFiles(
                sprintf('%s/%s', $this->INSTALL_DIR, $key),
                sprintf('%s/bitrix/%s', $_SERVER["DOCUMENT_ROOT"], $key)
            );
        }
    }
}
