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
 *     "printer_serial": "Form3XYZ",
 *     "billing_mode": "surcharge"
 *   }
 */

// === Configuration ===
const WEBHOOK_TOKEN           = 'your_webhook_token';
const FABMAN_API_URL          = 'https://fabman.io/api/v1/';
const FABMAN_TOKEN            = 'your_fabman_api_token';
const FORMLABS_CLIENT_ID      = 'your_formlabs_client_id';
const FORMLABS_USER           = 'your_user_email@example.com';
const FORMLABS_PASSWORD       = 'your_formlabs_password';
const DESC_TEMPLATE_BASE      = '3D print %s on %s';
const DESC_TEMPLATE_SURCHARGE = '3D print %s on %s - surcharge for %.2f ml %s';

function debug(string $message): void {
    echo "[DEBUG] $message\n";
}

$currentTz = ini_get('date.timezone') ?: date_default_timezone_get();
debug("Using timezone: $currentTz");

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
$logId = (int)$log->id;
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

if (isset($log->metadata->{'Formlabs Printjob'})) {
    debug("Metadata already contains 'Formlabs Printjob'. Skipping billing.");
    http_response_code(202);
    exit("Job already processed – skipping.");
}

$result = call_api('GET', "resources/{$resource_id}");
if ($result['http_code'] !== 200) {
    http_response_code(500);
    exit("Failed to fetch metadata for resource {$resource_id}");
}
$resource_metadata = $result['data']->metadata ?? null;
if (!is_object($resource_metadata)) {
    http_response_code(500);
    exit("Invalid or missing metadata for resource {$resource_id}");
}
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

// === Parse timestamps ===
$created_at_fabman = new DateTimeImmutable($log->createdAt);
$stopped_at_fabman = new DateTimeImmutable($log->stoppedAt);

$print_started_at = isset($print_job->print_started_at) ? new DateTimeImmutable($print_job->print_started_at) : null;
$print_finished_at = isset($print_job->print_finished_at) ? new DateTimeImmutable($print_job->print_finished_at) : null;

// === Perform plausibility check ===
if (!$print_started_at || !$print_finished_at) {
    debug("Print job has missing start or end timestamp – skipping.");
    http_response_code(202);
    exit("Missing Formlabs print timestamps.");
}

if (
    $created_at_fabman > $print_started_at ||
    $print_started_at > $print_finished_at ||
    $print_finished_at > $stopped_at_fabman
) {
    debug("Timestamp order invalid:");
    debug("Fabman createdAt      : " . $created_at_fabman->format(DateTime::ATOM));
    debug("Formlabs start        : " . $print_started_at->format(DateTime::ATOM));
    debug("Formlabs finish       : " . $print_finished_at->format(DateTime::ATOM));
    debug("Fabman stoppedAt      : " . $stopped_at_fabman->format(DateTime::ATOM));
    http_response_code(202);
    exit("Timestamp order invalid – skipping print job.");
}

// === Calculate Prices based on billing mode ===
$printName          = $print_job->name ?? "n/a";
$materialCode       = $print_job->material ?? 'n/a';
$volumeMl           = $print_job->volume_ml ?? 0;
$defaultPricePerMl  = $resource_metadata->price_per_ml;
$materialPricePerMl = $resource_metadata->{$materialCode}->price_per_ml ?? null;
$billingMode        = $resource_metadata->billing_mode ?? 'default';

debug("Material: $materialCode, Volume: $volumeMl ml, Mode: $billingMode");

// Time & identifiers
$memberId     = (int)$log->member;
$stoppedAt    = $log->stoppedAt;
$dateTime     = date(
    "Y-m-d\TH:i:s",
    strtotime(strlen($stoppedAt) === 10 ? "$stoppedAt T00:00:00" : $stoppedAt)
);
$resourceName = $payload->details->resource->name ?? 'unknown device';

if ($billingMode === 'surcharge') {
    // === Mode 2: Surcharge Mode ===

    // 1) Always create base charge using default price
    $basePrice = round($volumeMl * $defaultPricePerMl, 2);
    $descBase  = sprintf(
        DESC_TEMPLATE_BASE,
        $printName,
        $resourceName,
    );

    create_charge($memberId, $dateTime, $descBase, $basePrice, $logId);
    debug("Created base charge: $basePrice");

    // 2) Always create material surcharge if material-specific price is set
    if ($materialPricePerMl !== null) {
        $surchargePrice = round($volumeMl * $materialPricePerMl, 2);
        $matName         = $resource_metadata->{$materialCode}->name ?? $materialCode;
        
        $descSurcharge  = sprintf(
            DESC_TEMPLATE_SURCHARGE,
            $printName,
            $resourceName,
            $volumeMl,
            $matName
        );

        create_charge($memberId, $dateTime, $descSurcharge, $surchargePrice, $logId);
        debug("Created material surcharge: $surchargePrice");
    }

} else {
    // === Mode 1: Default Mode ===

    $unitPrice = $materialPricePerMl ?? $defaultPricePerMl;
    $price     = round($volumeMl * $unitPrice, 2);
    $matName   = $materialPricePerMl !== null
        ? ($resource_metadata->{$materialCode}->name ?? $materialCode)
        : null;
        
    $desc  = sprintf(
        DESC_TEMPLATE_BASE,
        $printName,
        $resourceName,
    );

    create_charge($memberId, $dateTime, $desc, $price, $logId);
    debug("Created single charge: $price");
}

// === Store metadata including pricing details ===
$jobMetadata = [
    "Formlabs Printjob" => array_merge(
        sanitize_print_job($print_job),
        [
            "_billed" => [
                "base_price"            => $basePrice ?? 0,
                "surcharge_price    "   => $surchargePrice ?? 0,
                "volume_ml"             => round($volumeMl, 2),
                "material_code"         => $materialCode,
                "billing_mode"          => $billingMode,
                "default_price_per_ml"  => $defaultPricePerMl,
                "material_price_per_ml" => $materialPricePerMl,
                "print_name"            => $printName
            ]
        ]
    )
];

debug("Updating metadata for resourceLog ID {$log->id}");
$metadataResult = set_log_metadata((int)$log->id, $jobMetadata, true);
if (in_array($metadataResult['http_code'], [200, 201, 204])) {
    debug("Metadata successfully updated.");
} else {
    debug("Failed to update metadata: HTTP {$metadataResult['http_code']}");
}

// Return summary to webhook caller
if ($billingMode === 'surcharge') {
    echo sprintf(
        "Charges created: Base €%.2f%s",
        $basePrice,
        $surchargePrice > 0 ? sprintf(" + Surcharge €%.2f", $surchargePrice) : ''
    );
} else {
    echo sprintf("Charge created: €%.2f", $basePrice);
}

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

function set_log_metadata(int $resource_log_id, array $new_metadata, bool $merge = true): array {
    $max_attempts = 5;
    $attempt = 0;

    do {
        $attempt++;
        $get_result = call_api("GET", "resource-logs/{$resource_log_id}");
        if ($get_result['http_code'] !== 200) {
            debug("Attempt #$attempt: Failed to load resource log (HTTP {$get_result['http_code']})");
            return $get_result;
        }

        $lock_version = $get_result['data']->lockVersion ?? null;
        if ($lock_version === null) {
            debug("Attempt #$attempt: No lockVersion found in response.");
            return ['http_code' => 400, 'data' => null, 'header' => []];
        }

        $existing_metadata = (array)($get_result['data']->metadata ?? []);
        $merged_metadata = $merge
            ? array_merge($existing_metadata, $new_metadata)
            : $new_metadata;

        $payload = [
            'metadata'    => $merged_metadata,
            'lockVersion' => $lock_version
        ];

        $put_result = call_api("PUT", "resource-logs/{$resource_log_id}", $payload);
        if (in_array($put_result['http_code'], [200, 201, 204])) {
            return $put_result;
        }

        debug("Attempt #$attempt: Failed to update metadata (HTTP {$put_result['http_code']}) – retrying...");

        usleep(200_000); // Optional: 200ms delay before retry
    } while ($attempt < $max_attempts);

    return $put_result; // return last attempt's result
}

function sanitize_print_job($job): array {
    return [
        "Print Name"   => $job->name ?? null,
        "Material"     => $job->material ?? null,
        "Material Name"=> $job->material_name ?? null,
        "Volume (ml)"  => isset($job->volume_ml) ? round($job->volume_ml, 2) : null,
        "Started At"   => $job->print_started_at ?? null,
        "Finished At"  => $job->print_finished_at ?? null,
        "Printer"      => $job->printer ?? null,
        "Status"       => $job->status ?? null,
        "Layer Count"  => $job->layer_count ?? null,
        "Layer Height" => $job->layer_thickness_mm ?? null,
        "Estimated Duration (min)" => isset($job->estimated_duration_ms)
            ? round($job->estimated_duration_ms / 60000)
            : null,
    ];
}
