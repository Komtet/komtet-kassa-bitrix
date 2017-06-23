<?php

require_once $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php";
require_once $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php";

IncludeModuleLangFile(__FILE__);

$APPLICATION->SetTitle(GetMessage('KOMTETKASSA_REPORTS_TITLE'));

if (!CModule::IncludeModule("komtet.kassa")) {
    ShowError(GetMessage('KOMTETKASSA_REPORTS_MODULE_INCLUDE_ERROR'));
    require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php";
}

$list = new CAdminList('komtetkassa_reports');
$list->addHeaders(array(
    array(
        "id" => 'id',
        "content" => GetMessage('KOMTETKASSA_REPORTS_ITEM_ID'),
        "default" => true,
    ),
    array(
        "id" => 'order_id',
        "content" => GetMessage('KOMTETKASSA_REPORTS_ITEM_ORDER_ID'),
        "default" => true,
    ),
    array(
        "id" => 'state',
        "content" => GetMessage('KOMTETKASSA_REPORTS_ITEM_STATE'),
        "default" => true,
    ),
    array(
        "id" => 'error_description',
        "content" => GetMessage('KOMTETKASSA_REPORTS_ITEM_ERROR_DESCR'),
        "default" => true,
    ),
));


$page = filter_input(INPUT_GET, 'PAGEN_1', FILTER_VALIDATE_INT);
$page = $page ? $page : 1;
$limit = 10;
$offset = abs(intval($page * $limit - $limit));
$totalItems = (int) KomtetKassaReportsTable::getCount();
$navData = new CDBResult();
$navData->NavPageCount = (int) ceil($totalItems / $limit);
$navData->NavPageNomer = $page;
$navData->NavNum = 1;
$navData->NavPageSize = $limit;
$navData->NavRecordCount = $totalItems;

$items = KomtetKassaReportsTable::getList(array(
    'order' => array('id' => 'DESC'),
    'offset' => $offset,
    'limit' => $limit
));

while ($item = $items->fetch()) {
    $list->AddRow('rowid', array(
        'id' => $item['id'],
        'order_id' => $item['order_id'],
        'state' => $item['state'] == 0 ? 'success' : 'failed',
        'error_description' => $item['error_description']
    ));
}

$list->DisplayList();

$APPLICATION->IncludeComponent('bitrix:system.pagenavigation', '', array('NAV_RESULT' => $navData));

require $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php";
