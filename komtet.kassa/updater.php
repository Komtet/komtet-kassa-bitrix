<?
use Bitrix\Main\ModuleManager;

$moduleId = 'komtet.kassa';

$currentVersion = ModuleManager::getVersion($moduleId);

global $DB;

if ($DB->TableExists('b_sale_store_barcode')) {

    $tableInfo = $DB->GetTableFields('b_sale_store_barcode');

    if (isset($tableInfo['MARKING_CODE'])) {
        $fieldInfo = $tableInfo['MARKING_CODE'];

        if ($fieldInfo['TYPE'] === 'string' && $fieldInfo['LENGTH'] < 400) {
            $sql = "ALTER TABLE b_sale_store_barcode MODIFY COLUMN MARKING_CODE VARCHAR(400)";
            try {
                $DB->Query($sql, false, "FILE: " . __FILE__ . " LINE: " . __LINE__);
            } catch (SqlQueryException $e) {
                AddMessage2Log("Ошибка обновления базы данных: " . $e->getMessage(), "komtet.kassa");
            }
        }
    }
}
