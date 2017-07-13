<?php

use Komtet\KassaSdk\Exception\SdkException;
use Komtet\KassaSdk\Check;
use Komtet\KassaSdk\Client;
use Komtet\KassaSdk\Payment;
use Komtet\KassaSdk\Position;
use Komtet\KassaSdk\QueueManager;
use Komtet\KassaSdk\Vat;

class KomtetKassa
{
    private $manager;
    private $shouldPrint;
    private $taxSystem;

    public function __construct()
    {
        $options = $this->getOptions();
        $client = new Client($options['key'], $options['secret']);
        if (!empty($options['server_url'])) {
            $client->setHost($options['server_url']);
        }
        $this->manager = new QueueManager($client);
        $this->manager->registerQueue('default', $options['queue_id']);
        $this->manager->setDefaultQueue('default');
        $this->shouldPrint = $options['should_print'];
        $this->taxSystem = $options['tax_system'];
    }

    public function printCheck($orderID)
    {
        $order = CSaleOrder::GetByID($orderID);
        $user = CUSer::GetByID($order['USER_ID'])->Fetch();
        $check = Check::createSell($orderID, $user['EMAIL'], $this->taxSystem);
        $check->setShouldPrint($this->shouldPrint);
        $check->addPayment(Payment::createCard(floatval($order['PRICE'])));

        $dbBasket = CSaleBasket::GetList(
            array("ID" => "ASC"),
            array("ORDER_ID" => $order['ID']),
            false,
            false,
            array("NAME", "QUANTITY", "PRICE", "DISCOUNT_PRICE", "VAT_RATE")
        );
        while ($item = $dbBasket->GetNext()) {
            $itemPrice = floatval($item['PRICE'] + $item['DISCOUNT_PRICE']);
            if ($this->taxSystem == Check::TS_COMMON) {
                $itemVat = Vat::calculate($itemPrice, round(floatval($item['VAT_RATE']), 2));
            } else {
                $itemVat = new Vat(0, Vat::RATE_NO);
            }
            $check->addPosition(new Position(
                $item['NAME'],
                $itemPrice,
                floatval($item['QUANTITY']),
                floatval($item['PRICE'] * $item['QUANTITY']),
                floatval($item['DISCOUNT_PRICE']),
                $itemVat
            ));
        }
        $deliveryPrice = floatval($order['PRICE_DELIVERY']);
        $check->addPosition(new Position('Доставка', $deliveryPrice, 1, $deliveryPrice, 0, new Vat(0, Vat::RATE_NO)));
        try {
            $this->manager->putCheck($check);
        } catch (SdkException $e) {
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
        }
    }

    public static function handleSalePayOrder($id, $val)
    {
        if ($val == 'N') {
            return;
        }
        if (!CModule::IncludeModule('sale')) {
            return;
        }
        $ok = new KomtetKassa();
        $ok->printCheck($id);
    }

    private function getOptions()
    {
        $moduleID = 'komtet.kassa';
        $result = array(
            'key' => COption::GetOptionString($moduleID, 'shop_id'),
            'secret' => COption::GetOptionString($moduleID, 'secret_key'),
            'queue_id' => COption::GetOptionString($moduleID, 'queue_id'),
            'server_url' => COption::GetOptionString($moduleID, 'server_url'),
            'should_print' => COption::GetOptionInt($moduleID, 'should_print') == 1,
            // intval() для совместимости с версиями ядра ниже 17-той
            'tax_system' => intval(COption::GetOptionInt($moduleID, 'tax_system'))
        );
        foreach (array('key', 'secret', 'queue_id', 'tax_system') as $key) {
            if (empty($result[$key])) {
                error_log(sprintf('Option "%s" for module "komtet.kassa" is required', $key));
            }
        }
        return $result;
    }
}
