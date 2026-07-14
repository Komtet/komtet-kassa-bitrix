<?php

use Komtet\KassaSdk\Exception\ApiValidationException;
use Komtet\KassaSdk\Exception\ClientException;
use Komtet\KassaSdk\Exception\SdkException;
use Komtet\KassaSdk\v2\Buyer;
use Komtet\KassaSdk\v2\Check;
use Komtet\KassaSdk\v2\Client;
use Komtet\KassaSdk\v2\Company;
use Komtet\KassaSdk\v2\MarkCode;
use Komtet\KassaSdk\v2\Measure;
use Komtet\KassaSdk\v2\Payment;
use Komtet\KassaSdk\v2\PaymentMethod;
use Komtet\KassaSdk\v2\PaymentObject;
use Komtet\KassaSdk\v2\Position;
use Komtet\KassaSdk\v2\SectoralItemProps;
use Komtet\KassaSdk\v2\TaxSystem;
use Komtet\KassaSdk\v2\QueueManager;
use Komtet\KassaSdk\v2\Vat;

use Bitrix\Main\Event;
use Bitrix\Sale\Order;
use Bitrix\Main\SiteTable;
use Bitrix\Main\UserTable;

use Bitrix\Main\Diag\Debug;


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

        $kk = new KomtetKassaOld();
        $kk->printCheck($id);
    }

    public static function newHandleSalePayOrder($order)
    {

        if (!$order instanceof Order) {
            return;
        }

        $kk = new KomtetKassaD7();
        $kk->printCheck($order);
    }

    public static function newHandleSaleSaveOrder($order)
    {

        if (!$order instanceof Order) {
            return;
        }

        $kk = new KomtetKassaD7();
        $kk->printCheck($order);
    }
}


class KomtetKassaBase
{
    protected $manager;
    protected $shouldPrint;
    protected $isInternet;
    protected $taxSystem;

    protected const DEFAULT_FEDERAL_ID = '030';
    protected const DEFAULT_DATE = '21.11.2023';
    protected const DEFAULT_NUMBER = '1944';

    protected static $measureMap = [
        'Метр' => Measure::METER,
        'Литр' => Measure::LITER,
        'Грамм' => Measure::GRAMM,
        'Килограмм' => Measure::KILOGRAMM,
        'Штука' => Measure::PIECE
    ];

    protected static $prePaymentVatMap = [
        0  => Vat::RATE_0,
        5  => Vat::RATE_105,
        7  => Vat::RATE_107,
        10 => Vat::RATE_110,
        20 => Vat::RATE_120,
        22 => Vat::RATE_122
    ];

    public function __construct()
    {
        $options = $this->getOptions();
        $client = new Client($options['key'], $options['secret']);
        $this->manager = new QueueManager($client);
        $this->manager->registerQueue('default', $options['queue_id']);
        $this->manager->setDefaultQueue('default');
        $this->shouldPrint = $options['should_print'];
        $this->isInternet = $options['is_internet'];
        $this->paymentObject = $options['calculation_subject'];
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
            'is_internet' => COption::GetOptionInt($moduleID, 'is_internet') == 1,
            'calculation_subject' => COption::GetOptionString($moduleID, 'calculation_subject', PaymentObject::PRODUCT),
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
                'paymentMethod' => PaymentMethod::FULL_PAYMENT,
                'paymentObject' => $this->paymentObject,
                'isFullPayment' => false
            );
        }
        // 2 checks way
        else if ($this->prepaymentOrderStatus) {
            // prepayment
            if ($orderStatus == $this->prepaymentOrderStatus) {
                return array(
                    'paymentMethod' => PaymentMethod::PRE_PAYMENT_FULL,
                    'paymentObject' => PaymentObject::PAYMENT,
                    'isFullPayment' => false
                );
            }
            // full payment
            else if ($orderStatus == $this->fullPaymentOrderStatus &&
                     ($orderExistingStatus == PaymentMethod::PRE_PAYMENT_FULL ||
                      $orderExistingStatus == PaymentMethod::PRE_PAYMENT_FULL.":done" ||
                      $orderExistingStatus == PaymentMethod::FULL_PAYMENT.":error")
                ) {
                return array(
                    'paymentMethod' => PaymentMethod::FULL_PAYMENT,
                    'paymentObject' => $this->paymentObject,
                    'isFullPayment' => true
                );
            }
        }

        return array(
            'paymentMethod' => null,
            'paymentObject' => null,
            'isFullPayment' => null
        );
    }

    protected function generatePosition($position, $payment_method = null, $payment_object = null, $quantity = 1)
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
        if ($payment_method == PaymentMethod::PRE_PAYMENT_FULL) {
            $vatPercent = (int) round($position->getVatRate() * 100);
            $itemVatRate = self::$prePaymentVatMap[$vatPercent];
        }

        $measure = Measure::PIECE;
        $bitrixPositionMeasure = mb_convert_encoding($position->getField('MEASURE_NAME'), 'UTF-8', LANG_CHARSET);
        if ($bitrixPositionMeasure) {
            $measure = $this->measureMap[$bitrixPositionMeasure] ?? Measure::PIECE;
        }

        $pos = new Position(
            mb_convert_encoding($position->getField('NAME'), 'UTF-8', LANG_CHARSET),
            round($position->getPrice(), 2),
            $quantity,
            round($position->getPrice()*$quantity, 2),
            new Vat($itemVatRate),
            $measure,
            $payment_method,
            $payment_object
        );

        return $pos;
    }

    public function getMarkingCodes($position_id)
    {
        /**
         * Получение списка маркировок
         * @param int $position_id Идентификатор позиции в заказе
         */
        global $DB;
        $strSql = "SELECT MARKING_CODE FROM b_sale_store_barcode WHERE MARKING_CODE != '' AND BASKET_ID = " . intval($position_id);
        $dbRes = $DB->Query($strSql, false);

        $mark_codes = [];
        while ($mark_code = $dbRes->Fetch()) {
            array_push($mark_codes, $mark_code['MARKING_CODE']);
        }

        return $mark_codes;
    }

    protected function getMarkingProps($markingCode) {
        $decoded = base64_decode($markingCode, true);
        if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
            $decodedMarkingCode = $decoded;
        } else {
            $decodedMarkingCode = $markingCode;
        }

        $result = [
            'code' => $decodedMarkingCode,
            'sectoral_props' => null,
        ];

        if (is_string($decodedMarkingCode) && strpos($decodedMarkingCode, '{') === 0) {
            $data = json_decode($decodedMarkingCode, true);
            if (is_array($data) && isset($data['code'])) {
                $result['code'] = $data['code'];

                $reqId = $data['reqId'] ?? null;
                $reqTimestamp = $data['reqTimestamp'] ?? null;
                $inst = $data['inst'] ?? null;
                $version = $data['version'] ?? null;

                if ($reqId && $reqTimestamp) {

                    if ($inst && $ver) {
                        $value = $value . '&Inst=' . $inst . '&Ver=' . $ver;
                    }

                    $result['sectoral_props'] = [
                        'federal_id' => self::DEFAULT_FEDERAL_ID,
                        'date'       => self::DEFAULT_DATE,
                        'number'     => self::DEFAULT_NUMBER,
                        'value'      => 'UUID=' . $reqId . '&Time=' . $reqTimestamp,
                    ];
                }
            }
        }

        return $result;
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
        $strSql = "SELECT * FROM b_sale_pay_system_action WHERE PAY_SYSTEM_ID = " . intval($paySystemId);
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

    protected function getSiteUrl($order)
    {
        $siteId = $order['LID'] ?? SITE_ID;
        $currentSite = CSite::GetByID($siteId);
        $siteOptions = $currentSite->Fetch();
        if ($siteOptions && $siteOptions['SERVER_NAME']) {
            $serverName = $siteOptions['SERVER_NAME'];
            $schema = (CMain::IsHTTPS() ? 'https' : 'http');
            $siteUrl = $schema . '://' . $serverName;
            return $siteUrl;
        }
        else {
            return SITE_SERVER_NAME;
        }
    }

    public function printCheck($orderID)
    {
        $order = CSaleOrder::GetByID($orderID);

        $paymentProps = $this->getPaymentProps($order['STATUS_ID'], true);

        $user = CUser::GetByID($order['USER_ID'])->Fetch();

        if (!$user) {
            return;
        }

        $userPhone = $user['PERSONAL_MOBILE'] ? $user['PERSONAL_MOBILE'] : $user['PERSONAL_PHONE'];

        $buyer = new Buyer();

        if ($user['EMAIL']) {
            $buyer->setEmail($user['EMAIL']);
        }
        else if ($userPhone) {
            $buyer->setPhone($userPhone);
        }

        $siteUrl = $this->getSiteUrl($order);
        $company = new Company($this->taxSystem, $siteUrl);

        $check = Check::createSell(
            $orderID,
            $buyer,
            $company
        );
        $check->setShouldPrint($this->shouldPrint);

        // Признак расчета в сети «Интернет»
        if ($this->isInternet) {
            $check->setInternet(true);
        }

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
            array("NAME", "QUANTITY", "PRICE", "DISCOUNT_PRICE", "VAT_RATE", "MEASURE_NAME")
        );

        while ($item = $dbBasket->GetNext()) {
            if ($this->taxSystem == TaxSystem::COMMON) {
                $itemVatRate = round(floatval($item['VAT_RATE']) * 100, 2);
            } else {
                $itemVatRate = Vat::RATE_NO;
            }

            $measure = Measure::PIECE;
            $bitrixPositionMeasure = mb_convert_encoding($item['MEASURE_NAME'], 'UTF-8', LANG_CHARSET);
            if ($bitrixPositionMeasure) {
                $measure = $this->measureMap[$bitrixPositionMeasure] ?? Measure::PIECE;
            }

            $checkPosition = new Position(
                mb_convert_encoding($item['NAME'], 'UTF-8', LANG_CHARSET),
                round($item['PRICE'], 2),
                floatval($item['QUANTITY']),
                round(($item['PRICE'] - $item['DISCOUNT_PRICE']) * $item['QUANTITY'], 2),
                new Vat($itemVatRate),
                $measure,
                $paymentProps['paymentMethod'],
                $paymentProps['positionObject']
            );

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
                new Vat(Vat::RATE_NO),
                Measure::PIECE,
                $paymentProps['paymentMethod'],
                PaymentObject::SERVICE
            );

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

    protected function getCheckPayment($payment, $isFullPayment)
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

    protected function getSiteUrl($order)
    {
        $siteId = $order->getSiteId() ?: SITE_ID;

        $siteData = SiteTable::getList([
            'filter' => ['=LID' => $siteId],
            'select' => ['SERVER_NAME']
        ])->fetch();

        if ($siteData && $siteData['SERVER_NAME']) {
            $serverName = $siteData['SERVER_NAME'];
            $schema = (CMain::IsHTTPS() ? 'https' : 'http');
            $siteUrl = $schema . '://' . $serverName;
            return $siteUrl;
        } else {
            return SITE_SERVER_NAME;
        }
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
             ($existingRow['state'] == PaymentMethod::FULL_PAYMENT ||
              $existingRow['state'] == PaymentMethod::FULL_PAYMENT.":done")) ||
            ($order->getField('STATUS_ID') == $this->prepaymentOrderStatus &&
             ($existingRow['state'] == PaymentMethod::PRE_PAYMENT_FULL ||
              $existingRow['state'] == PaymentMethod::PRE_PAYMENT_FULL.":done")) ||
            $existingRow['state'] == "done" ||
            ($this->fiscalizationStartDate &&
             (
                $order->getDateInsert()->getTimestamp() <
                DateTime::createFromFormat('d.m.Y', $this->fiscalizationStartDate)->getTimestamp()
             ))
             ||
            (
                !in_array($order->getField('STATUS_ID'), [$this->fullPaymentOrderStatus, $this->prepaymentOrderStatus])
            )
        ) {
            return;
        }

        $paymentProps = $this->getPaymentProps($order->getField('STATUS_ID'), $existingRow['state']);

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

        $buyer = new Buyer();
        $buyer->setEmail($userEmail);
        $buyer->setPhone($userPhone);

        $siteUrl = $this->getSiteUrl($order);
        $company = new Company($this->taxSystem, $siteUrl);

        $check = Check::createSell(
            $order->getId(),
            $buyer,
            $company
        );
        $check->setShouldPrint($this->shouldPrint);

        // Признак расчета в сети «Интернет»
        if ($this->isInternet) {
            $check->setInternet(true);
        }

        $checkPayments = array();
        $innerBillPayments = array();
        $paymentCollection = $order->getPaymentCollection();
        foreach ($paymentCollection as $payment) {
            if ($payment->isInner()) {
                $innerBillPayments[] = $payment;
                continue;
            } elseif ($this->paySystems and !in_array($payment->getPaymentSystemId(), $this->paySystems)) {
                continue;
            }

            $checkPayment = $this->getCheckPayment($payment, $paymentProps['isFullPayment']);
            $checkPayments[] = $checkPayment;
        }

        if (empty($checkPayments)) {
            return;
        }

        foreach ($checkPayments as $checkPayment) {
            $check->addPayment($checkPayment);
        }

        $positions = $order->getBasket();

        foreach ($positions as $position) {
            if ($position->getField('MARKING_CODE_GROUP') // товару в позиции присвоена группа маркировки: обувь, табак и т.п.
                && $paymentProps['paymentMethod'] == PaymentMethod::FULL_PAYMENT)
            {
                $positionID = $position->getField('ID');
                $mark_codes = $this->getMarkingCodes($positionID); // маркировки этого товара

                if (!$mark_codes || count($mark_codes) < $position->getQuantity()) {
                    KomtetKassaReportsTable::add([
                        'order_id' => $order->getId(),
                        'state' => $paymentProps['paymentMethod'].":error",
                        'error_description' => "Маркировки заданы не у всех товаров"]
                    );
                    return;
                }

                for ($item = 0; $item < $position->getQuantity(); $item++) {
                    $marked_position = $this->generatePosition(
                        $position,
                        $paymentProps['paymentMethod'],
                        $paymentProps['paymentObject']
                    );
                    $mark_code = array_shift($mark_codes);

                    $marking_props = $this->getMarkingProps($mark_code);

                    $marked_position->setMarkCode(new MarkCode(MarkCode::GS1M, $marking_props['code']));

                    if (isset($marking_props['sectoral_props'])) {
                        $marked_position->setSectoralItemProps(
                            new SectoralItemProps(
                                $marking_props['sectoral_props']['federal_id'],
                                $marking_props['sectoral_props']['date'],
                                $marking_props['sectoral_props']['number'],
                                $marking_props['sectoral_props']['value']
                            )
                        );
                    }

                    $check->addPosition($marked_position);
                }
            } else {
                $check->addPosition($this->generatePosition(
                    $position,
                    $paymentProps['paymentMethod'],
                    $paymentProps['paymentObject'],
                    $position->getQuantity()
                ));
            }
        }

        foreach ($innerBillPayments as $innerBillPayment) {
            $check->applyDiscount(round($innerBillPayment->getSum(), 2));
        }

        $shipmentCollection = $order->getShipmentCollection();
        foreach ($shipmentCollection as $shipment) {

            if ($shipment->getPrice() > 0.0) {
                // Если в Битриксе у доставки ставка НДС "БЕЗ НДС", то НДС возвращается как 0
                $shipmentVatRate = Vat::RATE_NO;

                if (method_exists($shipment, 'getVatRate') && floatval($shipment->getVatRate())) {
                    $shipmentVatRate = floatval($shipment->getVatRate());
                }

                /**
                 * К авансам под поставку товаров, облагаемых НДС, применяем расчётную ставку
                 * Ставка НДС в Битрикс хранится дробно, поэтому преобразовываем её для сравнения.
                 * К примеру, НДС 20% в битрикс 0.2, НДС 5% в битриксе 0.05.
                 */
                if ($paymentProps['paymentMethod'] == PaymentMethod::PRE_PAYMENT_FULL) {
                    $vatPercent = (int) round($shipment->getVatRate() * 100);
                    $shipmentVatRate = self::$prePaymentVatMap[$vatPercent] ?? Vat::RATE_NO;
                }

                $shipmentPosition = new Position(
                    mb_convert_encoding($shipment->getField('DELIVERY_NAME'), 'UTF-8', LANG_CHARSET),
                    round($shipment->getPrice(), 2),
                    1,
                    round($shipment->getPrice(), 2),
                    new Vat($shipmentVatRate),
                    Measure::PIECE,
                    $paymentProps['paymentMethod'],
                    PaymentObject::SERVICE
                );

                $check->addPosition($shipmentPosition);
            }
        }

        try {
            $this->manager->putCheck($check);
        } catch (ApiValidationException $e) {
            KomtetKassaReportsTable::add([
                'order_id' => $order->getId(),
                'state' => $paymentProps['paymentMethod'].":error",
                'error_description' => $e->getMessage()." ".$e->getDescription()
                ]
            );
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
            return;
        } catch (SdkException $e) {
            KomtetKassaReportsTable::add([
                'order_id' => $order->getId(),
                'state' => $paymentProps['paymentMethod'].":error",
                'error_description' => $e->getMessage()
                ]
            );
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
            return;
        } catch (ClientException $e) {
            KomtetKassaReportsTable::add([
                'order_id' => $order->getId(),
                'state' => $paymentProps['paymentMethod'].":error",
                'error_description' => $e->getMessage()
                ]
            );
            error_log(sprintf('Failed to send check: %s', $e->getMessage()));
            return;
        }
        KomtetKassaReportsTable::add([
            'order_id' => $order->getId(),
            'state' => $paymentProps['paymentMethod'].":done",
            'error_description' => '']
        );
    }
}
