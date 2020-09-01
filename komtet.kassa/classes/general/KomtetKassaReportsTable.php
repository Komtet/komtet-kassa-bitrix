<?php

use Bitrix\Main\Entity;
use Bitrix\Main\Type;

class KomtetKassaReportsTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'komtet_kassa_reports';
    }

    public static function getMap()
    {
        return array(
            new Entity\IntegerField('id', array(
                'primary' => true,
                'column_name' => 'id'
            )),
            new Entity\IntegerField('order_id', array(
                'required' => true,
                'column_name' => 'order_id'
            )),
            new Entity\StringField('state', array(
                'required' => true,
                'column_name' => 'state'
            )),
            new Entity\StringField('error_description', array(
                'default_value' => '',
                'required' => false,
                'column_name' => 'error_description'
            )),
        );
    }
}
