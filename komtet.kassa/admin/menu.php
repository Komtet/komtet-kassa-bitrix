<?php

IncludeModuleLangFile(__FILE__);

return array(
    'parent_menu' => 'global_menu_services',
    'section' => 'komtet.kassa',
    'sort' => 500,
    'text' => GetMessage('KOMTETKASSA_MENU_TEXT'),
    'title' => GetMessage('KOMTETKASSA_MENU_TEXT'),
    'icon' => 'currency_menu_icon',
    'page_icon' => 'currency_page_icon',
    'items_id' => 'menu_komtet_kassa',
    'items' => array(
        array(
            'text' => GetMessage('KOMTETKASSA_MENU_REPORTS_TEXT'),
            'title' => GetMessage('KOMTETKASSA_MENU_REPORTS_TEXT'),
            'url' => 'komtet_kassa_reports.php?lang='.LANGUAGE_ID
        )
    )
);
