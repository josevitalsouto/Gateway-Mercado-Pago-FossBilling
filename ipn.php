<?php
/**
 * IPN global para gateways locais e Mercado Pago.
 * Identifica o gateway de origem antes de repassar para o FOSSBilling.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'load.php';

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

$di = include Path::join(PATH_ROOT, 'di.php');
$di['translate']();
$filesystem = new Filesystem();

function ipn_gateway_by_id($di, int $gatewayId): ?array
{
    $gateway = $di['db']->getRow(
        'SELECT * FROM pay_gateway WHERE id = :id LIMIT 1',
        [':id' => $gatewayId]
    );

    return $gateway ?: null;
}

function ipn_gateway_by_name($di, string $name): ?array
{
    $gateway = $di['db']->getRow(
        'SELECT * FROM pay_gateway WHERE gateway = :name AND enabled = 1 LIMIT 1',
        [':name' => $name]
    );

    return $gateway ?: null;
}

function ipn_gateway_config(array $gateway): array
{
    $config = $gateway['config'] ?? null;
    if (!is_string($config) || $config === '') {
        return [];
    }

    $decoded = json_decode($config, true);
    return is_array($decoded) ? $decoded : [];
}

function ipn_merge_request_payload(array $payload): void
{
    $_POST = array_merge($_POST, $payload);
    $_REQUEST = array_merge($_REQUEST, $payload);
}

function ipn_fetch_mercadopago_payment(string $paymentId, string $accessToken): ?array
{
    $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$result) {
        return null;
    }

    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : null;
}

function ipn_resolve_mp_gateway($di, string $paymentId, ?int $invoiceId): ?array
{
    $gateways = $di['db']->getAll(
        'SELECT * FROM pay_gateway WHERE enabled = 1 AND gateway IN ("MercadoPago", "Pix") ORDER BY id ASC'
    );

    foreach ($gateways as $gateway) {
        $config = ipn_gateway_config($gateway);
        $accessToken = $config['access_token'] ?? null;
        if (!$accessToken) {
            continue;
        }

        $payment = ipn_fetch_mercadopago_payment($paymentId, $accessToken);
        if (!$payment) {
            continue;
        }

        $method = strtolower((string) ($payment['payment_method_id'] ?? ''));
        $details = is_array($payment['transaction_details'] ?? null) ? $payment['transaction_details'] : [];
        $isPixPayment = $method === 'pix'
            || !empty($details['qr_code'])
            || !empty($details['external_resource_url']);
        $externalReference = (string) ($payment['external_reference'] ?? '');

        if (($gateway['gateway'] ?? '') === 'Pix') {
            if ($isPixPayment) {
                error_log("[IPN] Gateway Pix identificado via payment {$paymentId}");
                return $gateway;
            }

            if ($externalReference === '' && $invoiceId) {
                error_log("[IPN] Gateway Pix selecionado por fallback sem external_reference no payment {$paymentId}");
                return $gateway;
            }
        }

        if (($gateway['gateway'] ?? '') === 'MercadoPago' && !$isPixPayment) {
            error_log("[IPN] Gateway MercadoPago identificado via payment {$paymentId}");
            return $gateway;
        }
    }

    return null;
}

function ipn_resolve_gateway($di, ?int $gatewayId, ?int $invoiceId, bool $isMercadoPago, ?string $paymentId): ?array
{
    if ($isMercadoPago && $paymentId) {
        $gateway = ipn_resolve_mp_gateway($di, $paymentId, $invoiceId);
        if ($gateway) {
            if ($gatewayId) {
                $explicitGateway = ipn_gateway_by_id($di, $gatewayId);
                if ($explicitGateway && (int) $explicitGateway['id'] !== (int) $gateway['id']) {
                    error_log("[IPN] Gateway explicito #{$explicitGateway['id']} ({$explicitGateway['gateway']}) substituido por #{$gateway['id']} ({$gateway['gateway']}) apos identificar o pagamento");
                }
            }
            return $gateway;
        }
    }

    if ($gatewayId) {
        $gateway = ipn_gateway_by_id($di, $gatewayId);
        if ($gateway) {
            error_log("[IPN] Gateway definido explicitamente: #{$gateway['id']} ({$gateway['gateway']})");
            return $gateway;
        }
    }

    if ($invoiceId) {
        try {
            $invoice = $di['db']->getRow(
                'SELECT gateway_id FROM invoice WHERE id = :id LIMIT 1',
                [':id' => $invoiceId]
            );

            if (!empty($invoice['gateway_id'])) {
                $gateway = ipn_gateway_by_id($di, (int) $invoice['gateway_id']);
                if ($gateway) {
                    error_log("[IPN] Gateway resolvido pela fatura #{$invoiceId}: #{$gateway['id']} ({$gateway['gateway']})");
                    return $gateway;
                }
            }
        } catch (Exception $e) {
            error_log('[IPN] Falha ao consultar gateway da fatura: ' . $e->getMessage());
        }
    }

    if ($isMercadoPago) {
        foreach (['MercadoPago', 'Pix'] as $name) {
            $gateway = ipn_gateway_by_name($di, $name);
            if ($gateway) {
                error_log("[IPN] Fallback para gateway ativo {$name} (#{$gateway['id']})");
                return $gateway;
            }
        }
    }

    return null;
}

$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$headerName] = $value;
        }
    }
}
$headers = array_change_key_case($headers, CASE_LOWER);

$rawInput = $filesystem->readFile('php://input');

$isMercadoPago = false;
$mpPaymentId = null;

if ($rawInput !== '') {
    $json = json_decode($rawInput, true);
    if (
        json_last_error() === JSON_ERROR_NONE
        && isset($json['data']['id'])
        && (($json['type'] ?? '') === 'payment' || strpos((string) ($json['action'] ?? ''), 'payment') !== false)
    ) {
        $isMercadoPago = true;
        $mpPaymentId = (string) $json['data']['id'];
        ipn_merge_request_payload($json);
    }
}

if (!$isMercadoPago && isset($_GET['topic']) && in_array($_GET['topic'], ['payment', 'merchant_order'], true)) {
    $isMercadoPago = true;
    $mpPaymentId = isset($_GET['resource']) ? (string) $_GET['resource'] : (isset($_GET['id']) ? (string) $_GET['id'] : null);

    if ($mpPaymentId) {
        ipn_merge_request_payload([
            'type' => $_GET['topic'],
            'action' => $_GET['topic'] . '.updated',
            'data' => ['id' => $mpPaymentId],
        ]);
        error_log("[IPN] GET Mercado Pago - resource: {$mpPaymentId}");
    }
}

error_log('[IPN] GET: ' . json_encode($_GET));
error_log('[IPN] POST: ' . json_encode($_POST));

$invoiceID = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : (isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : null);
$gatewayID = isset($_POST['gateway_id']) ? (int) $_POST['gateway_id'] : (isset($_GET['gateway_id']) ? (int) $_GET['gateway_id'] : null);

error_log("[IPN] invoiceID: {$invoiceID} | gatewayID: {$gatewayID}");

// 🔒 Deduplicação atômica: se o Mercado Pago enviou GET+POST simultâneos para o mesmo
// payment ID, apenas um será processado. Usa flock() em vez de file_exists() para
// evitar race conditions.
$ipnLockHandle = null;
if ($isMercadoPago && $mpPaymentId) {
    $lockDir = sys_get_temp_dir();
    $lockFile = $lockDir . '/ipn_mp_' . md5($mpPaymentId) . '.lock';
    $ipnLockHandle = @fopen($lockFile, 'c');

    if ($ipnLockHandle && !flock($ipnLockHandle, LOCK_EX | LOCK_NB)) {
        // Outra requisição já está processando este mesmo payment ID
        error_log("[IPN] ⏭ Duplicado ignorado para payment {$mpPaymentId}");
        fclose($ipnLockHandle);
        http_response_code(200);
        header('Content-type: application/json');
        echo json_encode(['status' => 'ok', 'skipped' => 'duplicate']);
        exit;
    }
}

$resolvedGateway = ipn_resolve_gateway($di, $gatewayID, $invoiceID, $isMercadoPago, $mpPaymentId);
if ($resolvedGateway) {
    $gatewayID = (int) $resolvedGateway['id'];
    error_log("[IPN] Gateway final: #{$gatewayID} ({$resolvedGateway['gateway']})");
}

if (empty($gatewayID)) {
    error_log('[IPN] Gateway ID requerido!');
    if ($ipnLockHandle) { flock($ipnLockHandle, LOCK_UN); fclose($ipnLockHandle); }
    http_response_code(400);
    exit;
}

$ipn = [
    'skip_validation' => true,
    'invoice_id' => $invoiceID,
    'gateway_id' => $gatewayID,
    'get' => $_GET,
    'post' => $_POST,
    'server' => $_SERVER,
    'headers' => $headers,
    'http_raw_post_data' => $rawInput,
];

try {
    $service = $di['mod_service']('invoice', 'transaction');
    $service->createAndProcess($ipn);
    error_log("[IPN] Processado gateway #{$gatewayID}");
} catch (Exception $e) {
    error_log('[IPN] ERRO: ' . $e->getMessage());
}

// Libera o lock após processamento
if ($ipnLockHandle) {
    flock($ipnLockHandle, LOCK_UN);
    fclose($ipnLockHandle);
}

http_response_code(200);
header('Content-type: application/json');
echo json_encode(['status' => 'ok']);
exit;
