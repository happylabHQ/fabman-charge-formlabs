<?php
/**
 * ChargeFormlabs.php
 *
 * Webhook for direct billing of Formlabs prints in Fabman.
 *
 * Usage:
 *   POST /ChargeFormlabs.php?secret=...&resources=1322,1516
 *
 * Notes:
 * - Charges are only created for resources listed in ?resources parameter.
 * - Material-specific pricing is supported via resource metadata:
 *   {
 *     "FLFL8001": {"name": "Flexible80A", "price_per_ml": 0.13},
 *     "price_per_ml": 1.0,
 *     "printer_serial": "Form3XYZ"
 *   }
 */

declare(strict_types=1);

// === Configuration ===
const WEBHOOK_TOKEN      = 'your_webhook_token';
const FABMAN_API_URL     = 'https://fabman.io/api/v1/';
const FABMAN_TOKEN       = 'your_fabman_api_token';
const FORMLABS_CLIENT_ID = 'your_formlabs_client_id';
const FORMLABS_USER      = 'your_user_email@example.com';
const FORMLABS_PASSWORD  = 'your_formlabs_password';

date_default_timezone_set('Europe/Vienna');

function debug(string $message): void {
    echo "[DEBUG] $message\n";
}

// === Validate webhook token ===
debug("Validating webhook token");
if (!isset($_GET['secret']) || $_GET['secret'] !== WEBHOOK_TOKEN) {
    http_response_code(403);
    exit('Invalid webhook token.');
}

// === Parse JSON payload ===
debug("Reading payload");
$input = file_get_contents('php://input');
$payload = json_decode($input);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit('Invalid JSON payload.');
} 

if (!isset($payload->details->log)) {
    http_response_code(202);
    exit('Missing log data in payload.');
}

$log = $payload->details->log;
debug("Processing resource log ID: {$log->id}");

if (isset($_GET['resources'])) {
    $allowed_resources = array_map('intval', explode(',', $_GET['resources']));
    debug("Allowed resource IDs: " . implode(',', $allowed_resources));
} else {
    http_response_code(202);
    exit("IDs of the resources to be considered must be provided as a URL parameter: ?resources=1322,1516");
}

$resource_id = (int)$log->resource;
debug("Resource ID: {$resource_id}");

if (!in_array($resource_id, $allowed_resources, true)) {
    http_response_code(202);
    exit("Resource ID $resource_id is not handled by this webhook.");
}

if (!isset($log->stopType)) {
    http_response_code(202);
    exit('Event is not a stop; nothing to do.');
}

$resource_metadata = get_resource_metadata($resource_id);
debug("Loaded resource metadata: " . json_encode($resource_metadata));

if ($resource_metadata === false || !isset($resource_metadata->price_per_ml, $resource_metadata->printer_serial)) {
    http_response_code(202);
    exit("Missing metadata for resource #{$resource_id}");
}

$access_token = formlabs_login(FORMLABS_CLIENT_ID, FORMLABS_USER, FORMLABS_PASSWORD);
debug("Obtained Formlabs access token: " . ($access_token ? 'yes' : 'no'));
if ($access_token === null) {
    http_response_code(500);
    exit('Server error: Formlabs authentication failed.');
}

$print_job = formlabs_get($access_token, $resource_metadata->printer_serial);
debug("Fetched print job: " . json_encode($print_job));
if ($print_job === null) {
    http_response_code(500);
    exit("Failed to fetch print job for printer serial {$resource_metadata->printer_serial}");
}

$material_code = $print_job->material ?? null;
$volume_ml = $print_job->volume_ml ?? 0;
debug("Material: $material_code, Volume: $volume_ml ml");

if ($material_code && isset($resource_metadata->{$material_code}->price_per_ml)) {
    $unit_price = $resource_metadata->{$material_code}->price_per_ml;
    debug("Using material-specific price: $unit_price €/ml");
} else {
    $unit_price = $resource_metadata->price_per_ml;
    debug("Using default price_per_ml: $unit_price €/ml");
}

$price = round($volume_ml * $unit_price, 2);
debug("Calculated price: €$price");

$member_id = (int)$log->member;
$stopped_at = $log->stoppedAt;
$date_time = strlen($stopped_at) === 10 ? $stopped_at . 'T00:00:00' : $stopped_at;
$date_time = date("Y-m-d\TH:i:s", strtotime($date_time));

$description = sprintf(
    'Formlabs print "%s" on %s',
    $print_job->name,
    $resource_metadata->name ?? 'unknown device'
);

debug("Creating charge for member ID $member_id on $date_time: $description");

$result = create_charge($member_id, $date_time, $description, $price, (int)$log->id);
if ($result['http_code'] !== 200 && $result['http_code'] !== 201 && $result['http_code'] !== 204) {
    http_response_code(500);
    exit("Failed to create charge: HTTP {$result['http_code']}");
}

echo "Charge created: €{$price}";

// === Functions ===
function formlabs_login(string $client_id, string $username, string $password): ?string {
    $url = 'https://api.formlabs.com/developer/v1/o/token/';
    $post_data = http_build_query([
        'grant_type' => 'password',
        'client_id' => $client_id,
        'username' => $username,
        'password' => $password,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        error_log("Formlabs login failed: HTTP $http_code");
        return null;
    }

    $data = json_decode($response);
    return $data->access_token ?? null;
}

function formlabs_get(string $access_token, string $printer_serial) {
    $url = "https://api.formlabs.com/developer/v1/printers/{$printer_serial}/prints/";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$access_token}"],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return null;
    }

    $data = json_decode($response);
    return $data->results[0] ?? null;
}

function call_api(string $method, string $endpoint, $data = null): array {
    $url = rtrim(FABMAN_API_URL, '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init();
    $headers = ['Authorization: Bearer ' . FABMAN_TOKEN];

    switch (strtoupper($method)) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                $json = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers[] = 'Content-Type: application/json';
            }
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data !== null) {
                $json = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers[] = 'Content-Type: application/json';
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
        default:
            if ($data !== null) {
                $url .= '?' . http_build_query($data);
            }
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);

    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $header_text = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    return [
        'http_code' => $http_code,
        'data' => json_decode($body),
        'header' => $header_text,
    ];
}

function get_resource_metadata(int $resource_id) {
    $result = call_api('GET', "resources/{$resource_id}");
    return ($result['http_code'] === 200)
        ? ($result['data']->metadata ?? false)
        : false;
}

function create_charge(int $member_id, string $date_time, string $description, float $price, int $resource_log_id = null): array {
    $payload = [
        'member' => $member_id,
        'dateTime' => $date_time,
        'description' => $description,
        'price' => $price,
    ];
    if ($resource_log_id !== null) {
        $payload['resourceLog'] = $resource_log_id;
    }
    return call_api('POST', 'charges', $payload);
}
