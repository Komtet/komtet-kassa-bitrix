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
        $this->manager = new QueueManager($client);
        $this->manager->registerQueue('default', $options['queue_id']);
        $this->manager->setDefaultQueue('default');
        $this->shouldPrint = $options['should_print'];
        $this->taxSystem = $options['tax_system'];
        $this->paySystems = $options['pay_systems'];
    }

    protected function getPayment($paySystemId, $personTypeId, $sum) {
        $arFilter = array(
            'PAY_SYSTEM_ID' => $paySystemId,
            'PERSON_TYPE_ID' => $personTypeId
        );
        $resPaySystemAction = CSalePaySystemAction::GetList(array(), $arFilter);
        while ($pAction = $resPaySystemAction->Fetch()) {
            $arPath = explode('/', $pAction['ACTION_FILE']);
            if (end($arPath) == 'cash') {
                return Payment::createCash($sum);
            }
        }
        return Payment::createCard($sum);
    }

    public function printCheck($orderID)
    {
        $order = CSaleOrder::GetByID($orderID);
        if ($this->paySystems and !in_array($order['PAY_SYSTEM_ID'], $this->paySystems)) {
          return;
        }

        $user = CUSer::GetByID($order['USER_ID'])->Fetch();
        $check = Check::createSell($orderID, $user['EMAIL'], $this->taxSystem);
        $check->setShouldPrint($this->shouldPrint);

        $payment = $this->getPayment($order['PAY_SYSTEM_ID'], $order['PERSON_TYPE_ID'],
                                     floatval($order['PRICE']));
        $check->addPayment($payment);

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
                $itemVatRate = round(floatval($item['VAT_RATE']), 2);
            } else {
                $itemVatRate = Vat::RATE_NO;
            }

            $check->addPosition(new Position(
                mb_convert_encoding($item['NAME'], 'UTF-8', LANG_CHARSET),
                $itemPrice,
                floatval($item['QUANTITY']),
                floatval($item['PRICE'] * $item['QUANTITY']),
                floatval($item['DISCOUNT_PRICE']),
                new Vat($itemVatRate)
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
        if (gettype($id) == 'object') {
            return;
        }

        if ($val == 'N') {
            return;
        }
        if (!CModule::IncludeModule('sale')) {
            return;
        }

        $ok = new KomtetKassa();
        $ok->printCheck($id);
    }

    public static function newHandleSalePayOrder($order)
    {
        if (!gettype($id) == 'object')
        {
            return;
        }

        if (!$order->isPaid()) {
            return;
        }

        $ok = new KomtetKassa();
        $ok->printCheck($order->getId());
    }

    private function getOptions()
    {
        $moduleID = 'komtet.kassa';
        $result = array(
            'key' => COption::GetOptionString($moduleID, 'shop_id'),
            'secret' => COption::GetOptionString($moduleID, 'secret_key'),
            'queue_id' => COption::GetOptionString($moduleID, 'queue_id'),
            'should_print' => COption::GetOptionInt($moduleID, 'should_print') == 1,
            'tax_system' => intval(COption::GetOptionInt($moduleID, 'tax_system')),
            'pay_systems' => json_decode(COption::GetOptionString($moduleID, 'pay_systems'))
        );
        foreach (array('key', 'secret', 'queue_id', 'tax_system') as $key) {
            if (empty($result[$key])) {
                error_log(sprintf('Option "%s" for module "komtet.kassa" is required', $key));
            }
        }
        return $result;
    }
}
