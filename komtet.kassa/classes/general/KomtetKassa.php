<?php

use Komtet\KassaSdk\Exception\ApiValidationException;
use Komtet\KassaSdk\Exception\ClientException;
use Komtet\KassaSdk\Exception\SdkException;
use Komtet\KassaSdk\v1\CalculationMethod;
use Komtet\KassaSdk\v1\CalculationSubject;
use Komtet\KassaSdk\v1\Check;
use Komtet\KassaSdk\v1\Client;
use Komtet\KassaSdk\v1\Nomenclature;
use Komtet\KassaSdk\v1\Payment;
use Komtet\KassaSdk\v1\Position;
use Komtet\KassaSdk\v1\TaxSystem;
use Komtet\KassaSdk\v1\QueueManager;
use Komtet\KassaSdk\v1\Vat;
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

        if (!gettype($order) == 'object') {
            return;
        }

        $ok = new KomtetKassaD7();
        $ok->printCheck($order);
    }

    public static function newHandleSaleSaveOrder($order)
    {

        if (!gettype($order) == 'object') {
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
        $this->calculationSubject = $options['calculation_subject'];
        $this->taxSystem = $options['tax_system'];
        $this->paySystems = $options['pay_systems'];
        $this->fullPaymentOrderStatus = $options['full_payment_order_status'];
        $this->prepaymentOrderStatus = $options['prepayment_order_status'];
        $this->fiscalizationStartDate = $options['fiscalization_start_date'];
    }

    private function getOptions()
    {
        /**
         * Получение настроек плагина
         */

        $moduleID = 'komtet.kassa';
        $result = array(
            'key' => COption::GetOptionString($moduleID, 'shop_id'),
            'secret' => COption::GetOptionString($moduleID, 'secret_key'),
            'queue_id' => COption::GetOptionString($moduleID, 'queue_id'),
            'should_print' => COption::GetOptionInt($moduleID, 'should_print') == 1,
            'calculation_subject' => COption::GetOptionString($moduleID, 'calculation_subject', CalculationSubject::PRODUCT),
            'tax_system' => intval(COption::GetOptionInt($moduleID, 'tax_system')),
            'pay_systems' => json_decode(COption::GetOptionString($moduleID, 'pay_systems')),
            'full_payment_order_status' => COption::GetOptionString($moduleID, 'full_payment_order_status'),
            'prepayment_order_status' => COption::GetOptionString($moduleID, 'prepayment_order_status'),
            'fiscalization_start_date' => COption::GetOptionString($moduleID, 'fiscalization_start_date')
        );
        foreach (array(
            'key', 'secret', 'queue_id','calculation_subject',
            'tax_system', 'full_payment_order_status'
        ) as $key) {
            if (empty($result[$key])) {
                error_log(sprintf('Option "%s" for module "komtet.kassa" is required', $key));
            }
        }
        return $result;
    }

    protected function getPaymentProps($orderStatus, $orderExistingStatus)
    {
        /**
         * Получение опций оплаты
         * @param string $orderStatus новый статус заказа
         * @param string $orderExistingStatus предыдущий статус заказа
         */

        // 1 check way
        if (!$this->prepaymentOrderStatus && $orderStatus == $this->fullPaymentOrderStatus) {
            return array(
                'calculationMethod' => CalculationMethod::FULL_PAYMENT,
                'calculationSubject' => $this->calculationSubject,
                'isFullPayment' => false
            );
        }
        // 2 checks way
        else if ($this->prepaymentOrderStatus) {
            // prepayment
            if ($orderStatus == $this->prepaymentOrderStatus) {
                return array(
                    'calculationMethod' => CalculationMethod::PRE_PAYMENT_FULL,
                    'calculationSubject' => CalculationSubject::PAYMENT,
                    'isFullPayment' => false
                );
            }
            // full payment
            else if ($orderStatus == $this->fullPaymentOrderStatus &&
                     ($orderExistingStatus == CalculationMethod::PRE_PAYMENT_FULL ||
                      $orderExistingStatus == CalculationMethod::PRE_PAYMENT_FULL.":done" ||
                      $orderExistingStatus == CalculationMethod::FULL_PAYMENT.":error")
                ) {
                return array(
                    'calculationMethod' => CalculationMethod::FULL_PAYMENT,
                    'calculationSubject' => $this->calculationSubject,
                    'isFullPayment' => true
                );
            }
        }

        return array(
            'calculationMethod' => null,
            'calculationSubject' => null,
            'isFullPayment' => null
        );
    }

    protected function generatePosition($position, $calc_method = null, $calc_subject = null, $quantity = 1)
    {
        /**
         * Получени позиции заказа
         * @param array $position Позицияв заказе Bitrix
         * @param int|float $quantity Количествово товара в позиции
         */

        $itemVatRate = Vat::RATE_NO;

        // Если в Битриксе у товара не выбрана ставка НДС или ставка "БЕЗ НДС", то НДС возвращается как 0
        if (floatval($position->getField('VAT_RATE'))) {
            $itemVatRate = floatval($position->getField('VAT_RATE'));
        }

        /**
         * К авансам под поставку товаров, облагаемых НДС, применяем расчётную ставку
         * Ставка НДС в Битрикс хранится дробно, поэтому преобразовываем её для сравнения.
         * К примеру, НДС 20% в битрикс 0.2, НДС 5% в битриксе 0.05.
         */
        if ($calc_method == CalculationMethod::PRE_PAYMENT_FULL) {
            if (intval((floatval($position->getField('VAT_RATE')) * 100)) == 5) {
                $itemVatRate = '5/105';
            }
            else if (intval((floatval($position->getField('VAT_RATE')) * 100)) == 7) {
                $itemVatRate = '7/107';
            }
            else if (intval((floatval($position->getField('VAT_RATE')) * 100)) == 10) {
                $itemVatRate = '10/110';
            }
            else if (intval((floatval($position->getField('VAT_RATE')) * 100)) == 20) {
                $itemVatRate = '20/120';
            }
        }

        $pos = new Position(
            mb_convert_encoding($position->getField('NAME'), 'UTF-8', LANG_CHARSET),
            round($position->getPrice(), 2),
            $quantity,
            round($position->getPrice()*$quantity, 2),
            new Vat($itemVatRate)
        );

        $pos->setCalculationMethod($calc_method);
        $pos->setCalculationSubject($calc_subject);

        return $pos;
    }

    public function getNomenclatureCodes($position_id)
    {
        /**
         * Получени списка маркировок
         * @param int $position_id Идентификатор позиции в заказе
         */
        global $DB;

        $strSql = "SELECT MARKING_CODE FROM b_sale_store_barcode WHERE MARKING_CODE != '' AND BASKET_ID = " . $position_id;
        $dbRes = $DB->Query($strSql, false);

        $nomenclature_codes = [];
        while ($nomenclature_code = $dbRes->Fetch()) {
            array_push($nomenclature_codes, $nomenclature_code['MARKING_CODE']);
        }
        return $nomenclature_codes;
    }

    /**
     * Убираем из телефона все, кроме цифр и символа '+' в начале номера, если он есть.
     * Для телефона, который начинается на 7 без '+' добавляем '+' в начало.
     */
    public function formatPhoneNumber($phoneNumber) {
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        if (substr($phoneNumber, 0, 1) == "7") {
            $phoneNumber = "+" . $phoneNumber;
        }

        return $phoneNumber;
    }

}


class KomtetKassaOld extends KomtetKassaBase
{
    protected function getPayment($paySystemId, $personTypeId, $sum, $isFullPayment)
    {
        global $DB;
        $strSql = "SELECT * FROM b_sale_pay_system_action WHERE PAY_SYSTEM_ID = $paySystemId";
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

                    // если FullPayment, то ставится prepayment - закрытие предоплаты
                    $type = $isFullPayment ? Payment::TYPE_PREPAYMENT : Payment::TYPE_CASH;

                    return new Payment($type, round($sum, 2));
                }
            }
        }

        // если FullPayment, то ставится prepayment - закрытие предоплаты
        $type = $isFullPayment ? Payment::TYPE_PREPAYMENT : Payment::TYPE_CARD;

        return new Payment($type, round($sum, 2));
    }

    public function printCheck($orderID)
    {
        $order = CSaleOrder::GetByID($orderID);

        $paymentProps = $this->getPaymentProps($order['STATUS_ID'], true);
        if ($paymentProps['calculationMethod'] == null) {
            return;
        }

        $user = CUser::GetByID($order['USER_ID'])->Fetch();
        $userPhone = $user['PERSONAL_MOBILE'] ? $user['PERSONAL_MOBILE'] : $user['PERSONAL_PHONE'];
        $check = Check::createSell(
            $orderID,
            $user['EMAIL'] ? $user['EMAIL'] : $userPhone,
            $this->taxSystem
        );
        $check->setShouldPrint($this->shouldPrint);

        $checkPayment = $this->getPayment(
            $order['PAY_SYSTEM_ID'],
            $order['PERSON_TYPE_ID'],
            floatval($order['PRICE']),
            $paymentProps['isFullPayment']
        );
        $check->addPayment($checkPayment);

        $dbBasket = CSaleBasket::GetList(
            array("ID" => "ASC"),
            array("ORDER_ID" => $order['ID']),
            false,
            false,
            array("NAME", "QUANTITY", "PRICE", "DISCOUNT_PRICE", "VAT_RATE")
        );

        while ($item = $dbBasket->GetNext()) {
            if ($this->taxSystem == TaxSystem::COMMON) {
                $itemVatRate = round(floatval($item['VAT_RATE']) * 100, 2);
            } else {
                $itemVatRate = Vat::RATE_NO;
            }

            $checkPosition = new Position(
                mb_convert_encoding($item['NAME'], 'UTF-8', LANG_CHARSET),
                round($item['PRICE'], 2),
                floatval($item['QUANTITY']),
                round(($item['PRICE'] - $item['DISCOUNT_PRICE']) * $item['QUANTITY'], 2),
                new Vat($itemVatRate)
            );

            if ($item['MEASURE_NAME']) {
                $checkPosition->setMeasureName(mb_convert_encoding($item['MEASURE_NAME'], 'UTF-8', LANG_CHARSET));
            }

            $checkPosition->setCalculationMethod($paymentProps['calculationMethod']);
            $checkPosition->setCalculationSubject($paymentProps['calculationSubject']);

            $check->addPosition($checkPosition);
        }

        $deliveryPrice = round($order['PRICE_DELIVERY'], 2);
        if ($deliveryPrice > 0.0) {
            $delivery = CSaleDelivery::GetByID($order['DELIVERY_ID']);

            $deliveryPosition = new Position(
                mb_convert_encoding($delivery['NAME'], 'UTF-8', LANG_CHARSET),
                $deliveryPrice,
                1,
                $deliveryPrice,
                new Vat(Vat::RATE_NO)
            );

            $deliveryPosition->setCalculationMethod($paymentProps['calculationMethod']);
            $deliveryPosition->setCalculationSubject(CalculationSubject::SERVICE);

            $check->addPosition($deliveryPosition);
        }

        try {
            $this->manager->putCheck($check);
        } catch (SdkException $e) {
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
        }
    }
}


class KomtetKassaD7 extends KomtetKassaBase
{

    protected function getPayment($payment, $isFullPayment)
    {

        $paySystem = $payment->getPaySystem();

        if ($paySystem->isCash()) {
            // если FullPayment, то ставится prepayment - закрытие предоплаты
            $type = $isFullPayment ? Payment::TYPE_PREPAYMENT : Payment::TYPE_CASH;

            return new Payment($type, round($payment->getSum(), 2));
        }

        // если FullPayment, то ставится prepayment - закрытие предоплаты
        $type = $isFullPayment ? Payment::TYPE_PREPAYMENT : Payment::TYPE_CARD;

        return new Payment($type, round($payment->getSum(), 2));
    }

    public function printCheck($order)
    {

        $existingRow = KomtetKassaReportsTable::getRow(
            array(
                'select' => array('*'),
                'filter' => array('order_id' => $order->getId()),
                'order' => array('id' => 'desc')
            )
        );

        if (
            ($order->getField('STATUS_ID') == $this->fullPaymentOrderStatus &&
             ($existingRow['state'] == CalculationMethod::FULL_PAYMENT ||
              $existingRow['state'] == CalculationMethod::FULL_PAYMENT.":done")) ||
            ($order->getField('STATUS_ID') == $this->prepaymentOrderStatus &&
             ($existingRow['state'] == CalculationMethod::PRE_PAYMENT_FULL ||
              $existingRow['state'] == CalculationMethod::PRE_PAYMENT_FULL.":done")) ||
            $existingRow['state'] == "done" ||
            ($this->fiscalizationStartDate &&
             (
                $order->getDateInsert()->getTimestamp() <
                DateTime::createFromFormat('d.m.Y', $this->fiscalizationStartDate)->getTimestamp()
             ))
        ) {
            return;
        }

        $paymentProps = $this->getPaymentProps($order->getField('STATUS_ID'), $existingRow['state']);

        if ($paymentProps['calculationMethod'] === null) {
            return;
        }

        $propertyCollection = $order->getPropertyCollection();
        $userEmail = $propertyCollection->getUserEmail();

        if ($userEmail) {
            $userEmail = $userEmail->getValue();
        } else { // if email field have not flag "is_email"
            foreach ($propertyCollection as $orderProperty) {
                if ($orderProperty->getField('CODE') == 'EMAIL') {
                    $userEmail = $orderProperty->getValue();
                    break;
                }
            }
        }

        // get user Phone
        $userPhone = $propertyCollection->getPhone();
        if ($userPhone) {
            $userPhone = $this->formatPhoneNumber($userPhone->getValue());
        } else { // if phone field don't have flag "is_phone"
            foreach ($propertyCollection as $orderField) {
                if ($orderField->getField('CODE') == 'PHONE') {
                    $userPhone = $this->formatPhoneNumber($orderField->getValue());
                    break;
                }
            }
        }

        $check = Check::createSell(
            $order->getId(),
            $userEmail ? $userEmail : $userPhone,
            $this->taxSystem
        );
        $check->setShouldPrint($this->shouldPrint);

        $payments = array();
        $innerBillPayments = array();
        $paymentCollection = $order->getPaymentCollection();
        foreach ($paymentCollection as $payment) {
            if ($payment->isInner()) {
                $innerBillPayments[] = $payment;
                continue;
            } elseif ($this->paySystems and !in_array($payment->getPaymentSystemId(), $this->paySystems)) {
                continue;
            }

            $checkPayment = $this->getPayment($payment, $paymentProps['isFullPayment']);
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
            if ($position->getField('MARKING_CODE_GROUP')
            && $paymentProps['calculationMethod'] == CalculationMethod::FULL_PAYMENT) {
                $positionID = $position->getField('ID');
                $nomenclature_codes = $this->getNomenclatureCodes($positionID);

                if (count($nomenclature_codes) < $position->getQuantity()) {
                    KomtetKassaReportsTable::add([
                        'order_id' => $order->getId(),
                        'state' => $paymentProps['calculationMethod'].":error",
                        'error_description' => "Маркировки заданы не у всех товаров"]
                    );
                    return;
                }

                for ($item = 0; $item < $position->getQuantity(); $item++) {
                    $marked_position = $this->generatePosition(
                        $position,
                        $paymentProps['calculationMethod'],
                        $paymentProps['calculationSubject']
                    );
                    $nomenclature_code = array_shift($nomenclature_codes);
                    $marked_position->setNomenclature(new Nomenclature($nomenclature_code));
                    $check->addPosition($marked_position);
                }
            } else {
                $check->addPosition($this->generatePosition(
                    $position,
                    $paymentProps['calculationMethod'],
                    $paymentProps['calculationSubject'],
                    $position->getQuantity()
                ));
            }
        }

        foreach ($innerBillPayments as $innerBillPayment) {
            $check->applyDiscount(round($innerBillPayment->getSum(), 2));
        }

        $shipmentCollection = $order->getShipmentCollection();
        foreach ($shipmentCollection as $shipment) {

            // Если в Битриксе у доставки ставка НДС "БЕЗ НДС", то НДС возвращается как 0
            if ($shipment->getPrice() > 0.0) {
                $shipmentVatRate = Vat::RATE_NO;

                if (method_exists($shipment, 'getVatRate') && floatval($shipment->getVatRate())) {
                    $shipmentVatRate = floatval($shipment->getVatRate());
                }

                /**
                 * К авансам под поставку товаров, облагаемых НДС, применяем расчётную ставку
                 * Ставка НДС в Битрикс хранится дробно, поэтому преобразовываем её для сравнения.
                 * К примеру, НДС 20% в битрикс 0.2, НДС 5% в битриксе 0.05.
                 */
                if ($paymentProps['calculationMethod'] == CalculationMethod::PRE_PAYMENT_FULL) {
                    if (intval((floatval($shipment->getVatRate()) * 100)) == 5) {
                        $shipmentVatRate = '5/105';
                    }
                    else if (intval((floatval($shipment->getVatRate()) * 100)) == 7) {
                        $shipmentVatRate = '7/107';
                    }
                    else if (intval((floatval($shipment->getVatRate()) * 100)) == 10) {
                        $shipmentVatRate = '10/110';
                    }
                    else if (intval((floatval($shipment->getVatRate()) * 100)) == 20) {
                        $shipmentVatRate = '20/120';
                    }
                }

                $shipmentPosition = new Position(
                    mb_convert_encoding($shipment->getField('DELIVERY_NAME'), 'UTF-8', LANG_CHARSET),
                    round($shipment->getPrice(), 2),
                    1,
                    round($shipment->getPrice(), 2),
                    new Vat($shipmentVatRate)
                );
                $shipmentPosition->setCalculationMethod($paymentProps['calculationMethod']);
                $shipmentPosition->setCalculationSubject(CalculationSubject::SERVICE);

                $check->addPosition($shipmentPosition);
            }
        }

        try {
            $this->manager->putCheck($check);
        } catch (ApiValidationException $e) {
            KomtetKassaReportsTable::add([
                'order_id' => $order->getId(),
                'state' => $paymentProps['calculationMethod'].":error",
                'error_description' => $e->getMessage()." ".$e->getDescription()
                ]
            );
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
            return;
        } catch (SdkException $e) {
            KomtetKassaReportsTable::add([
                'order_id' => $order->getId(),
                'state' => $paymentProps['calculationMethod'].":error",
                'error_description' => $e->getMessage()
                ]
            );
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
            return;
        } catch (ClientException $e) {
            KomtetKassaReportsTable::add([
                'order_id' => $order->getId(),
                'state' => $paymentProps['calculationMethod'].":error",
                'error_description' => $e->getMessage()
                ]
            );
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
            return;
        }
        KomtetKassaReportsTable::add([
            'order_id' => $order->getId(),
            'state' => $paymentProps['calculationMethod'].":done",
            'error_description' => '']
        );
    }
}
