<?php

class komtet_kassa extends CModule
{
    public $MODULE_ID = 'komtet.kassa';
    public $MODULE_VERSION = '0.1.0';
    public $MODULE_VERSION_DATE = '2017-06-16 09:00';
    public $MODULE_NAME = 'КОМТЕТ Касса';
    public $MODULE_DESCRIPTION = 'Интеграция с сервисом печати чеков КОМТЕТ Касса';
    private $INSTALL_DIR;

    public function __construct()
    {
        $this->INSTALL_DIR = dirname(__file__);
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
