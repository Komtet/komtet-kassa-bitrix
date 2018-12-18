<?php

use Komtet\KassaSdk\Exception\SdkException;
use Komtet\KassaSdk\Check;
use Komtet\KassaSdk\Client;
use Komtet\KassaSdk\Payment;
use Komtet\KassaSdk\Position;
use Komtet\KassaSdk\QueueManager;
use Komtet\KassaSdk\Vat;

use Bitrix\Main\UserTable;


class KomtetKassa
{
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

        $ok = new KomtetKassaOld();
        $ok->printCheck($id);
    }

    public static function newHandleSalePayOrder($order)
    {

        if (!gettype($order) == 'object')
        {
            return;
        }

        if (!$order->isPaid()) {
            return;
        }

        $ok = new KomtetKassaD7();
        $ok->printCheck($order);
    }

    public static function newHandleSaleSaveOrder($order)
    {

        if (!gettype($order) == 'object')
        {
            return;
        }

        if (!$order->isPaid()) {
            return;
        }

        $ok = new KomtetKassaD7();
        $ok->printCheck($order);
    }
}


class KomtetKassaBase
{
    protected $manager;
    protected $shouldPrint;
    protected $taxSystem;

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

    private function getOptions() {
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


class KomtetKassaOld extends KomtetKassaBase
{
    protected function getPayment($paySystemId, $personTypeId, $sum) {
        global $DB;
        $strSql = "SELECT * FROM b_sale_pay_system_action WHERE  PAY_SYSTEM_ID = $paySystemId";
        $res = $DB->Query($strSql);

        while ($pAction = $res->Fetch()) {
            $arFilter = array(
                'PAY_SYSTEM_ID' => $paySystemId,
                'PERSON_TYPE_ID' => $personTypeId
            );
            $resPaySystemAction = CSalePaySystemAction::GetList(array(), $arFilter);
            while ($pAction = $resPaySystemAction->Fetch()) {
                $arPath = explode('/', $pAction['ACTION_FILE']);
                if (end($arPath) == 'cash') {
                    return Payment::createCash(round($sum, 2));
                }
            }
        }
        return Payment::createCard(round($sum, 2));
    }

    public function printCheck($orderID) {
        $order = CSaleOrder::GetByID($orderID);

        $user = CUSer::GetByID($order['USER_ID'])->Fetch();
        $check = Check::createSell($orderID, $user['EMAIL'], $this->taxSystem);
        $check->setShouldPrint($this->shouldPrint);

        $checkPayment = $this->getPayment($order['PAY_SYSTEM_ID'],
                                          $order['PERSON_TYPE_ID'],
                                          floatval($order['PRICE']));
        $check->addPayment($checkPayment);

        $dbBasket = CSaleBasket::GetList(
            array("ID" => "ASC"),
            array("ORDER_ID" => $order['ID']),
            false,
            false,
            array("NAME", "QUANTITY", "PRICE", "DISCOUNT_PRICE", "VAT_RATE")
        );

        while ($item = $dbBasket->GetNext()) {
            if ($this->taxSystem == Check::TS_COMMON) {
                $itemVatRate = round(floatval($item['VAT_RATE']), 2);
            } else {
                $itemVatRate = Vat::RATE_NO;
            }

            $check->addPosition(new Position(
                mb_convert_encoding($item['NAME'], 'UTF-8', LANG_CHARSET),
                round($item['PRICE'], 2),
                floatval($item['QUANTITY']),
                round(($item['PRICE'] - $item['DISCOUNT_PRICE']) * $item['QUANTITY'], 2),
                floatval($item['DISCOUNT_PRICE']),
                new Vat($itemVatRate)
            ));
        }

        $deliveryPrice = round($order['PRICE_DELIVERY'], 2);
        if ($deliveryPrice > 0.0) {
            $delivery = CSaleDelivery::GetByID($order['DELIVERY_ID']);
            $check->addPosition(new Position(
                mb_convert_encoding($delivery['NAME'], 'UTF-8', LANG_CHARSET),
                $deliveryPrice,
                1,
                $deliveryPrice,
                0.0,
                new Vat(0, Vat::RATE_NO)));
        }

        try {
            $this->manager->putCheck($check);
        } catch (SdkException $e) {
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
        }
    }
}


class KomtetKassaD7 extends KomtetKassaBase {

    protected function getPayment($payment) {

        $paySystem = $payment->getPaySystem();

        if ($paySystem->isCash()) {
            return Payment::createCash(round($payment->getSum(), 2));
        }
        return Payment::createCard(round($payment->getSum(), 2));
    }

    public function printCheck($order) {

        $propertyCollection = $order->getPropertyCollection();
        $userEmail = $propertyCollection->getUserEmail();
        if ($userEmail) {
            $userEmail = $userEmail->getValue();
        }
        else { // if email field have not flag "is_email"
            foreach($propertyCollection as $orderProperty) {
                if($orderProperty->getField('CODE') == 'EMAIL') {
                    $userEmail = $orderProperty->getValue();
                    break;
                }
            }
        }

        // get email from order user
        if (!$userEmail) {
            $userId = $order->getUserId();
            $rsUser = UserTable::getById($userId);
            $user = $rsUser->fetch();
            $userEmail = $user['EMAIL'];
        }

        $check = Check::createSell($order->getId(), $userEmail, $this->taxSystem);
        $check->setShouldPrint($this->shouldPrint);

        $payments = array();
        $paymentCollection = $order->getPaymentCollection();
        foreach ($paymentCollection as $payment) {
            if ($this->paySystems and !in_array($payment->getPaymentSystemId(), $this->paySystems)) {
                continue;
            }

            $checkPayment = $this->getPayment($payment);
            $payments[] = $checkPayment;
        }

        if (empty($payments)) {
            return;
        }

        foreach ($payments as $payment) {
            $check->addPayment($payment);
        }

        $positions = $order->getBasket();
        foreach ($positions as $position) {
            if ($this->taxSystem == Check::TS_COMMON) {
                $itemVatRate = round(floatval($position->getField('VAT_RATE')), 2);
            } else {
                $itemVatRate = Vat::RATE_NO;
            }

            $check->addPosition(new Position(
                mb_convert_encoding($position->getField('NAME'), 'UTF-8', LANG_CHARSET),
                round($position->getPrice(), 2),
                $position->getQuantity(),
                round($position->getFinalPrice(), 2),
                0.0,
                new Vat($itemVatRate)
            ));
        }

        $shipmentCollection = $order->getShipmentCollection();
        foreach ($shipmentCollection as $shipment) {
            if ($shipment->getPrice() > 0.0) {
                $check->addPosition(new Position(
                    mb_convert_encoding($shipment->getField('DELIVERY_NAME'), 'UTF-8', LANG_CHARSET),
                    round($shipment->getPrice(), 2),
                    1,
                    round($shipment->getPrice(), 2),
                    0.0,
                    new Vat(0, Vat::RATE_NO)));
            }
        }

        try {
            $this->manager->putCheck($check);
        } catch (SdkException $e) {
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
        }
    }
}
