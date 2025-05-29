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
 * - Charges are only created for resources listed in the ?resources parameter.
 * - Material-specific pricing is supported via resource metadata, for example:
 *   {
 *     "FLFL8001": {"name": "Flexible80A", "price_per_ml": 0.13},
 *     "price_per_ml": 1.0,
 *     "printer_serial": "Form3XYZ",
 *     "billing_mode": "surcharge"
 *   }
 */

declare(strict_types=1);

// Enable full error reporting and log errors to output
ini_set('display_errors',         '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors',             '1'); // Errors logged to stdout
ini_set('error_log',              'php://output');

// === Configuration ===
const WEBHOOK_TOKEN           = 'your_webhook_token';
const FABMAN_API_URL          = 'https://fabman.io/api/v1/';
const FABMAN_TOKEN            = 'your_fabman_api_token';
const FORMLABS_CLIENT_ID      = 'your_formlabs_client_id';
const FORMLABS_USER           = 'your_user_email@example.com';
const FORMLABS_PASSWORD       = 'your_formlabs_password';
const DESC_TEMPLATE_BASE      = '3D print %s on %s';
const DESC_TEMPLATE_SURCHARGE = '3D print %s on %s - surcharge for %.2f ml %s';
/*
DESC_TEMPLATE_BASE:
   %s → print job name
   %s → Fabman resource name
DESC_TEMPLATE_SURCHARGE:
   %s → print job name
   %s → Fabman resource name
   %.2f → volume in ml
   %s → material name
*/

/**
 * Logs debug messages to output.
 */
function debugLog(string $message): void
{
    echo "[DEBUG] {$message}\n";
}

// Determine and log current timezone
$currentTimezone = ini_get('date.timezone') ?: date_default_timezone_get();
debugLog("Using timezone: {$currentTimezone}");

// === Validate webhook token ===
debugLog('Validating webhook token');
if (!isset($_GET['secret']) || $_GET['secret'] !== WEBHOOK_TOKEN) {
    http_response_code(403);
    exit('Invalid webhook token.');
}

// === Read and parse JSON payload ===
debugLog('Reading payload');
$input   = file_get_contents('php://input');
$payload = json_decode($input);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit('Invalid JSON payload.');
}

$type = $payload->type ?? null;
if (!in_array($type, ['resourceLog_created', 'resourceLog_updated'], true)) {
    http_response_code(202);
    exit("Webhook type {$type} not processed.");
}

if (!isset($payload->details->log)) {
    http_response_code(202);
    exit('Missing log data in payload.');
}

$logEntry = $payload->details->log;
$logId    = (int) $logEntry->id;
debugLog("Processing resource log ID: {$logId}");

// === Determine allowed resources IDs ===
if (isset($_GET['resources'])) {
    $allowedResources = array_map('intval', explode(',', (string) $_GET['resources']));
    debugLog('Allowed resource IDs: ' . implode(',', $allowedResources));
} else {
    http_response_code(202);
    exit('Resource IDs must be provided as a URL parameter: ?resources=1322,1516');
}

$resourceId = (int) $logEntry->resource;
debugLog("Resource ID: {$resourceId}");

if (!in_array($resourceId, $allowedResources, true)) {
    http_response_code(202);
    exit("Resource ID {$resourceId} is not handled by this webhook.");
}

if (!isset($logEntry->stopType)) {
    http_response_code(202);
    exit('Event is not a stop; nothing to do.');
}

if (isset($logEntry->metadata->{'Formlabs Printjob'})) {
    debugLog("Metadata already contains 'Formlabs Printjob'; skipping billing.");
    http_response_code(202);
    exit('Job already processed – skipping.');
}

// === Fetch resource metadata from Fabman ===
$response = callFabmanApi('GET', "resources/{$resourceId}");
if ($response['http_code'] !== 200) {
    http_response_code(500);
    exit("Failed to fetch metadata for resource {$resourceId}.");
}

$resourceMetadata = $response['data']->metadata ?? null;
if (!is_object($resourceMetadata) ||
    !isset($resourceMetadata->price_per_ml, $resourceMetadata->printer_serial)) {
    http_response_code(500);
    exit("Invalid or missing metadata for resource {$resourceId}.");
}
debugLog('Loaded resource metadata: ' . json_encode($resourceMetadata));

// === Authenticate with Formlabs ===
$accessToken = formLabsLogin(FORMLABS_CLIENT_ID, FORMLABS_USER, FORMLABS_PASSWORD);
debugLog('Obtained Formlabs access token: ' . ($accessToken !== null ? 'yes' : 'no'));
if ($accessToken === null) {
    http_response_code(500);
    exit('Server error: Formlabs authentication failed.');
}

// === Determine activity timeframe ===
$activityStart = new DateTimeImmutable($logEntry->createdAt);
$activityEnd   = new DateTimeImmutable($logEntry->stoppedAt);

// === Retrieve printjobs from Formlabs ===
$prints = formLabsGetPrints(
    $accessToken,
    $resourceMetadata->printer_serial,
    $activityStart,
    $activityEnd
);
debugLog('Fetched print jobs: ' . json_encode($prints));
if ($prints === null) {
    http_response_code(500);
    exit("Failed to fetch prints for printer {$resourceMetadata->printer_serial}.");
}

$memberId     = (int) $logEntry->member;
$printCount   = count($prints);
$resourceName = $payload->details->resource->name ?? 'unknown device';

// no print jobs during this activity period
if ($printCount === 0) {
    http_response_code(202);
    exit('No Formlabs prints during this activity.');

// a single print job during this activity period
} elseif ($printCount === 1) {
    $printJob = $prints[0];

    // activity duration matches the print job exactly, so start billing
    if (timerangesMatch($logEntry, $printJob)) {
        debugLog('Timeranges match exactly, processing print job');
        processSinglePrintJob(
            $printJob,
            $logId,
            $memberId,
            $activityStart,
            $activityEnd,
            $resourceMetadata,
            $resourceName
        );   
        
    // activity is longer than the print job, so adjust the activity duration
    } else {
        debugLog('Timeranges differ, shrinking activity');
        $pjStart = new DateTimeImmutable($printJob->print_started_at);
        $pjEnd   = !empty($printJob->print_finished_at)
            ? new DateTimeImmutable($printJob->print_finished_at)
            : new DateTimeImmutable();
        $createdAtUtc = $pjStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $stoppedAtUtc = $pjEnd  ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $timeRes = updateActivityTimestamps($logId, $createdAtUtc, $stoppedAtUtc);
        if (!in_array($timeRes['http_code'], [200,201,204], true)) {
            http_response_code(500);
            exit(sprintf(
                "Failed to update metadata: HTTP %d, Response: %s",
                $timeRes['http_code'],
                json_encode($timeRes['data'])
            ));
        }
    }

// multiple print jobs occurred during the activity, so split into separate activities
} else {
    debugLog("Multiple print jobs found: {$printCount}");
    foreach ($prints as $printJob) {
        $jobName    = $printJob->name              ?? 'n/a';
        $startedAt  = $printJob->print_started_at  ?? 'n/a';
        $finishedAt = $printJob->print_finished_at ?? 'n/a';
        echo "{$startedAt}\t{$finishedAt}\t{$jobName}\n";
    }

    // delete the original activity
    $deleteResult = callFabmanApi('DELETE', "resource-logs/{$logId}");
    if ($deleteResult['http_code'] !== 204) {
        http_response_code(500);
        exit("Failed to delete original activity {$logId}.");
    }

    // create a new activity for each print job
    foreach (array_reverse($prints) as $printJob) {
        $pjStart = new DateTimeImmutable($printJob->print_started_at);
        $pjEnd   = !empty($printJob->print_finished_at)
            ? new DateTimeImmutable($printJob->print_finished_at)
            : new DateTimeImmutable();

        $createdAtUtc = $pjStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $stoppedAtUtc = $pjEnd  ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $newLogPayload = [
            'resource'  => $resourceId,
            'member'    => $memberId,
            'createdAt' => $createdAtUtc,
            'stoppedAt' => $stoppedAtUtc,
        ];

        $createLog = callFabmanApi('POST', 'resource-logs', $newLogPayload);
        if ($createLog['http_code'] !== 201) {
            debugLog("Failed to create new activity for job {$printJob->id}");
            continue;
        }
    }

    exit("Split Fabman activity into {$printCount} separate activities according to Formlabs prints.");
}

// === Core Functions ===

/**
 * Processes a single print job: validates timestamps, computes pricing,
 * creates charges, updates metadata, and shrinks the activity duration.
 */
function processSinglePrintJob(
    object                $printJob,
    int                   $logId,
    int                   $memberId,
    DateTimeImmutable     $activityStart,
    DateTimeImmutable     $activityEnd,
    object                $resourceMetadata,
    string                $resourceName
): void {
    global $currentTimezone;

    // Parse timestamps
    $printStartedAt  = isset($printJob->print_started_at)
        ? new DateTimeImmutable($printJob->print_started_at)
        : null;
    $printFinishedAt = isset($printJob->print_finished_at)
        ? new DateTimeImmutable($printJob->print_finished_at)
        : null;

    // Validate timestamps
    if (!$printStartedAt || !$printFinishedAt) {
        debugLog('Print job missing timestamps; skipping.');
        http_response_code(202);
        exit('Missing Formlabs print timestamps.');
    }

    if (
        $activityStart > $printStartedAt ||
        $printStartedAt > $printFinishedAt ||
        $printFinishedAt > $activityEnd
    ) {
        debugLog('Invalid timestamp order; skipping print job.');
        http_response_code(202);
        exit('Timestamp order invalid; skipping print job.');
    }

    // Extract pricing data
    $printName           = $printJob->name                          ?? 'n/a';
    $materialCode        = $printJob->material                      ?? 'n/a';
    $volumeMl            = $printJob->volume_ml                     ?? 0.0;
    $defaultPricePerMl   = $resourceMetadata->price_per_ml;
    $materialPricePerMl  = $resourceMetadata->{$materialCode}->price_per_ml ?? null;
    $billingMode         = $resourceMetadata->billing_mode          ?? 'default';

    debugLog(sprintf(
        "Processing print '%s': material=%s, volume=%.2fml, mode=%s",
        $printName,
        $materialCode,
        $volumeMl,
        $billingMode
    ));

    // Determine charge timestamp (end of print)
    $chargeDateTime = $printFinishedAt
        ->setTimezone(new DateTimeZone($currentTimezone))
        ->format('Y-m-d\TH:i');
    debugLog("Charge dateTime: {$chargeDateTime}");

    // Billing logic: surcharge vs default
    if ($billingMode === 'surcharge') {
        // Base charge
        debugLog('Billing mode: ' . $billingMode);
        $basePrice = round($volumeMl * $defaultPricePerMl, 2);
        $descBase  = sprintf(DESC_TEMPLATE_BASE, $printName, $resourceName);
        debugLog("createFabmanCharge($memberId, '$chargeDateTime', '$descBase', $basePrice, $logId)");
        $resBase   = createFabmanCharge($memberId, $chargeDateTime, $descBase, $basePrice, $logId);        
        handleChargeResult($resBase, $basePrice, 'base');

        // Additional material surcharge
        if ($materialPricePerMl !== null) {
            $surchargePrice = round($volumeMl * $materialPricePerMl, 2);
            $matName        = $resourceMetadata->{$materialCode}->name ?? $materialCode;
            $descSurch      = sprintf(DESC_TEMPLATE_SURCHARGE,
                $printName,
                $resourceName,
                $volumeMl,
                $matName
            );
            $resSurch       = createFabmanCharge($memberId, $chargeDateTime, $descSurch, $surchargePrice, null);
            handleChargeResult($resSurch, $surchargePrice, 'surcharge');
        }
    } else {
        // Single combined charge
        $unitPrice  = $materialPricePerMl ?? $defaultPricePerMl;
        $totalPrice = round($volumeMl * $unitPrice, 2);
        $desc       = sprintf(DESC_TEMPLATE_BASE, $printName, $resourceName);
        $res        = createFabmanCharge($memberId, $chargeDateTime, $desc, $totalPrice, $logId);
        handleChargeResult($res, $totalPrice, 'single');
    }


    #$pause = 5;
    #debugLog("Waiting for $pause seconds");
    #sleep($pause);


    // Update activity metadata
    $jobMeta = [
        'Formlabs Printjob' => array_merge(
            sanitizePrintJob($printJob),
            ['_billed' => [
                'base_price'            => $basePrice ?? 0,
                'surcharge_price'       => $surchargePrice ?? 0,
                'volume_ml'             => round($volumeMl, 2),
                'material_code'         => $materialCode,
                'billing_mode'          => $billingMode,
                'default_price_per_ml'  => $defaultPricePerMl,
                'material_price_per_ml' => $materialPricePerMl,
                'print_name'            => $printName,
            ]]
        )
    ];

    debugLog('Updating activity metadata (Formlabs Printjob): ' . json_encode($jobMeta['Formlabs Printjob']));
    $metaRes = updateActivityMetadata($logId, $jobMeta, true);
    if (!in_array($metaRes['http_code'], [200,201,204], true)) {
        http_response_code(500);
        exit(sprintf(
            "Failed to update metadata: HTTP %d, Response: %s",
            $metaRes['http_code'],
            json_encode($metaRes['data'])
        ));
    }

    /*
    // Shrink activity duration
    $createdAtUtc = $printStartedAt
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s\Z');
    $stoppedAtUtc = $printFinishedAt
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s\Z');

    debugLog("Shrinking activity duration: $createdAtUtc - $stoppedAtUtc");
    $timeRes = updateActivityTimestamps($logId, $createdAtUtc, $stoppedAtUtc);
    if (!in_array($timeRes['http_code'], [200,201,204], true)) {
        http_response_code(500);
        exit(sprintf(
            "Failed to update metadata: HTTP %d, Response: %s",
            $timeRes['http_code'],
            json_encode($timeRes['data'])
        ));
    }
    */

    /*
    debugLog("Updating activity {$logId}: shrink to print duration and add metadata");
    $updRes = updateActivity($logId, $createdAtUtc, $stoppedAtUtc, $jobMeta, true);
    if (!in_array($updRes['http_code'], [200, 201, 204], true)) {
        debugLog("Failed to update metadata: HTTP {$updRes['http_code']}");
    }
    */

    // Output result summary
    echo ($billingMode === 'surcharge')
        ? sprintf("Charges created: Base €%.2f + Surcharge €%.2f", $basePrice, $surchargePrice)
        : sprintf("Charge created: €%.2f", $totalPrice);
}

/**
 * Handles API charge results, exiting on error.
 */
function handleChargeResult(array $response, float $amount, string $type): void
{
    if (!in_array($response['http_code'], [200, 201, 204], true)) {
        $errorMsg = sprintf(
            'Failed to create %s charge: HTTP %d, Response: %s',
            $type,
            $response['http_code'],
            json_encode($response['data'])
        );
        debugLog($errorMsg);
        http_response_code(500);
        exit('Billing error: ' . $errorMsg);
    }
    debugLog(sprintf('Created %s charge: € %.2f (HTTP %d)', $type, $amount, $response['http_code']));
}

/**
 * Authenticates with Formlabs and returns an access token.
 */
function formLabsLogin(string $clientId, string $username, string $password): ?string
{
    $url  = 'https://api.formlabs.com/developer/v1/o/token/';
    $post = http_build_query([
        'grant_type' => 'password',
        'client_id'  => $clientId,
        'username'   => $username,
        'password'   => $password,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code !== 200) {
        error_log("Formlabs login failed: HTTP $code");
        return null;
    }

    $data = json_decode($resp);
    return $data->access_token ?? null;
}

/**
 * Retrieves completed print jobs from Formlabs within a time window.
 */
function formLabsGetPrints(string $accessToken, string $printerSerial, DateTimeInterface $from, DateTimeInterface $to): ?array
{
    $perPage = 100;
    $page    = 1;
    $all     = [];

    $fromStr = $from->format('Y-m-d\TH:i:s\Z');
    $toStr   = $to  ->format('Y-m-d\TH:i:s\Z');

    do {
        $url = sprintf(
            'https://api.formlabs.com/developer/v1/printers/%s/prints/?date__gt=%s&date__lt=%s&per_page=%d&page=%d',
            urlencode($printerSerial),
            urlencode($fromStr),
            urlencode($toStr),
            $perPage,
            $page
        );
        debugLog("Calling Formlabs API: {$url}");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code !== 200) {
            debugLog("Formlabs API error: HTTP {$code}");
            return null;
        }

        $data = json_decode($resp);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data->results)) {
            debugLog("Formlabs API: invalid response");
            return null;
        }

        foreach ($data->results as $pj) {
            if (empty($pj->print_started_at) || empty($pj->print_finished_at)) {
                continue;
            }
            try {
                $start = new DateTimeImmutable($pj->print_started_at);
                $end   = new DateTimeImmutable($pj->print_finished_at);
            } catch (Exception $e) {
                debugLog("Invalid date in job {$pj->guid}: {$e->getMessage()}");
                continue;
            }
            if ($start >= $from && $end <= $to) {
                $all[] = $pj;
            }
        }

        $fetched = count($data->results);
        $page++;
    } while ($fetched === $perPage);

    return $all;
}

/**
 * Generic API caller for Fabman endpoints.
 */
function callFabmanApi(string $method, string $endpoint, $data = null): array
{
    $url     = rtrim(FABMAN_API_URL, '/') . '/' . ltrim($endpoint, '/');
    $ch      = curl_init();
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
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $response    = curl_exec($ch);
    $headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = substr($response, $headerSize);
    return [
        'http_code' => $httpCode,
        'data'      => json_decode($body),
        'header'    => substr($response, 0, $headerSize),
    ];
}

/**
 * Creates a charge in Fabman for the given member.
 */
function createFabmanCharge(int $memberId, string $dateTime, string $description, float $price, int $resourceLogId = null): array
{
    $payload = [
        'member'      => $memberId,
        'dateTime'    => $dateTime,
        'description' => $description,
        'price'       => $price,
    ];
    if ($resourceLogId !== null) {
        $payload['resourceLog'] = $resourceLogId;
    }
    debugLog('Fabman Charge payload: ' . json_encode($payload));
    return callFabmanApi('POST', 'charges', $payload);
}

/**
 * Shrinkt eine Activity auf neue Start-/Stopp-Zeiten, mit Retry bei Lock-Conflicts.
 */
function updateActivityTimestamps(int $resourceLogId, string $createdAt, string $stoppedAt): array
{
    $maxAttempts = 5;
    $attempt     = 0;
    do {
        $attempt++;
        // 1) Load current lockVersion
        $get = callFabmanApi('GET', "resource-logs/{$resourceLogId}");
        if ($get['http_code'] !== 200) {
            return $get;
        }
        $lockVersion = $get['data']->lockVersion ?? null;

        // 2) Versuch, nur die Zeiten zu setzen
        $payload = [
            'createdAt'   => $createdAt,
            'stoppedAt'   => $stoppedAt,
            'lockVersion' => $lockVersion,
        ];
        $put = callFabmanApi('PUT', "resource-logs/{$resourceLogId}", $payload);

        if (in_array($put['http_code'], [200, 201, 204], true)) {
            return $put;
        }

        // ggf. warten und retryen
        debugLog("Timestamp-Update Versuch #{$attempt} fehlgeschlagen (HTTP {$put['http_code']}); retrying");
        usleep(200000);
    } while ($attempt < $maxAttempts);

    return $put;
}


/**
 * Aktualisiert nur die Metadaten einer Activity, mit Retry bei Lock-Conflicts.
 */
function updateActivityMetadata(int $resourceLogId, array $newMetadata, bool $merge = true): array
{
    $maxAttempts = 5;
    $attempt     = 0;
    do {
        $attempt++;
        // 1) Aktuelles Log mit Metadata und LockVersion holen
        $get = callFabmanApi('GET', "resource-logs/{$resourceLogId}");
        if ($get['http_code'] !== 200) {
            return $get;
        }
        $lockVersion      = $get['data']->lockVersion ?? null;
        $existingMetadata = (array)($get['data']->metadata ?? []);
        $payloadMeta      = $merge
            ? array_merge($existingMetadata, $newMetadata)
            : $newMetadata;

        // 2) Versuch, nur die Metadata zu setzen
        $payload = [
            'metadata'    => $payloadMeta,
            'lockVersion' => $lockVersion,
        ];
        $put = callFabmanApi('PUT', "resource-logs/{$resourceLogId}", $payload);

        if (in_array($put['http_code'], [200, 201, 204], true)) {
            return $put;
        }

        debugLog("Metadata-Update Versuch #{$attempt} fehlgeschlagen (HTTP {$put['http_code']}); retrying");
        usleep(200000);
    } while ($attempt < $maxAttempts);

    return $put;
}

/**
 * Updates a resource-log activity with new timestamps and metadata.
 */
/*
function updateActivity(int $resourceLogId, string $createdAt, string $stoppedAt, array $newMetadata, bool $merge = true): array
{
    $maxAttempts = 5;
    $attempt     = 0;

    do {
        $attempt++;
        $getRes = callFabmanApi('GET', "resource-logs/{$resourceLogId}");
        if ($getRes['http_code'] !== 200) {
            debugLog("Attempt #{$attempt}: failed to load resource log (HTTP {$getRes['http_code']})");
            return $getRes;
        }

        $lockVersion       = $getRes['data']->lockVersion ?? null;
        $existingMetadata  = (array)($getRes['data']->metadata ?? []);
        $mergedMetadata    = $merge ? array_merge($existingMetadata, $newMetadata) : $newMetadata;

        $payload = [
            'createdAt'   => $createdAt,
            'stoppedAt'   => $stoppedAt,
            'metadata'    => $mergedMetadata,
            'lockVersion' => $lockVersion,
        ];

        $putRes = callFabmanApi('PUT', "resource-logs/{$resourceLogId}", $payload);
        if (in_array($putRes['http_code'], [200, 201, 204], true)) {
            return $putRes;
        }
        debugLog("Attempt #{$attempt}: failed to update metadata (HTTP {$putRes['http_code']}); retrying...");
        usleep(200000);
    } while ($attempt < $maxAttempts);

    return $putRes;
}
*/

/**
 * Sanitizes a Formlabs print job for metadata storage.
 */
function sanitizePrintJob(object $job): array
{
    return [
        'printName'            => $job->name               ?? null,
        'material'             => $job->material           ?? null,
        'materialName'         => $job->material_name      ?? null,
        'volumeMl'             => isset($job->volume_ml)   ? round($job->volume_ml, 2) : null,
        'startedAt'            => $job->print_started_at   ?? null,
        'finishedAt'           => $job->print_finished_at  ?? null,
        'printer'              => $job->printer            ?? null,
        'status'               => $job->status             ?? null,
        'layerCount'           => $job->layer_count        ?? null,
        'layerHeightMm'        => $job->layer_thickness_mm ?? null,
        'estimatedDurationMin' => isset($job->estimated_duration_ms)
            ? (int) round($job->estimated_duration_ms / 60000)
            : null,
    ];
}

/**
 * Prüft, ob die Timeranges aus Webhook-Payload und Formlabs-Job exakt zusammenpassen.
 *
 * @param object $logEntry  Das Objekt aus dem Webhook, mit ->createdAt und ->stoppedAt (ISO-Strings)
 * @param object $printJob  Das Formlabs-Job-Objekt, mit ->print_started_at und ->print_finished_at
 * @return bool             True, wenn Start- und End-Zeit sekundengenau übereinstimmen
 */
function timerangesMatch(object $logEntry, object $printJob): bool
{
    // existenz prüfen
    if (empty($logEntry->createdAt) || empty($logEntry->stoppedAt)
     || empty($printJob->print_started_at) || empty($printJob->print_finished_at)) {
        return false;
    }

    try {
        $logStart   = new DateTimeImmutable($logEntry->createdAt);
        $logEnd     = new DateTimeImmutable($logEntry->stoppedAt);
        $printStart = new DateTimeImmutable($printJob->print_started_at);
        $printEnd   = new DateTimeImmutable($printJob->print_finished_at);
    } catch (Exception $e) {
        // ungültiges Datum
        return false;
    }

    // in UTC normalize und als Integer vergleichen
    return $logStart->setTimezone(new DateTimeZone('UTC'))->getTimestamp() ===
           $printStart->setTimezone(new DateTimeZone('UTC'))->getTimestamp()
       && $logEnd  ->setTimezone(new DateTimeZone('UTC'))->getTimestamp() ===
           $printEnd  ->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
}
