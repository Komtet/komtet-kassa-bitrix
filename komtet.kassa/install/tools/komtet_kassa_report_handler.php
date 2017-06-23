<?php

if (!array_key_exists('HTTP_X_HMAC_SIGNATURE', $_SERVER)) {
    header('HTTP/1.1 401 Unauthorized');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    header('Allow: POST');
    exit();
}

define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

if (!CModule::IncludeModule('komtet.kassa')) {
    header('HTTP/1.1 500 Internal Server Error');
    error_log('Unable to include komtet.kassa module');
    exit();
}

$secret = COption::GetOptionString('komtet.kassa', 'secret_key');
if (empty($secret)) {
    error_log('Unable to handle komtet.kassa report: secret is not defined');
    header('HTTP/1.1 500 Internal Server Error');
    exit();
}

$scheme = array_key_exists('HTTPS', $_SERVER) && strtolower($_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
$url = sprintf('%s://%s%s', $scheme, $_SERVER['SERVER_NAME'], $_SERVER['REQUEST_URI']);
$data = file_get_contents('php://input');
$signature = hash_hmac('md5', $_SERVER['REQUEST_METHOD'] . $url . $data, $secret);
if ($signature != $_SERVER['HTTP_X_HMAC_SIGNATURE']) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

$data = json_decode($data, true);
foreach (array('external_id', 'state') as $key) {
    if (!array_key_exists($key, $data)) {
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: text/plain');
        echo $key." is required\n";
        exit();
    }
}
$orderID = $data['external_id'];
$success = $data['state'] == 'done';
$errorDescription = $success && array_key_exists('error_description', $data) ? $data['error_description'] : '';

try {
    KomtetKassaReportsTable::add([
        'order_id' => $orderID,
        'state' => $success ? 0 : 1,
        'error_description' => $errorDescription]
    );
} catch (\Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    error_log(sprintf('Unable to add report from komtet kassa: %s', $e->getMessage()));
    exit();
}

header('HTTP/1.1 200 OK');
exit();
