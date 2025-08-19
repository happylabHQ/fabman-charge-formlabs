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
 *
 * Typical flow:
 * This webhook is usually triggered multiple times for a single Fabman activity of a Formlabs 3D printer:
 *
 * 1) Activity starts (Fabman Bridge is powered on):
 *    - No action is taken, as the activity is not yet completed.
 *
 * 2) Activity ends (Fabman Bridge is powered off):
 *    - The webhook queries the Formlabs API for print jobs that were *finished* during the activity's duration.
 *
 *    - Case A: No matching print jobs found
 *        → The activity is deleted.
 *
 *    - Case B: Multiple print jobs found
 *        → The original Fabman activity is split into multiple activities,
 *          each matching the exact start and end time of a print job (according to the Formlabs API).
 *
 *    - Case C: Exactly one print job found, but timestamps differ
 *        → The Fabman activity's start and/or end time is adjusted to match the print job.
 *
 *    - Case D: Exactly one print job found, and timestamps match
 *        → The activity is billed.
 *
 *    - Note: For cases B and C, the webhook is triggered again for each newly created activity.
 *            These will then fall into Case D and be billed accordingly.
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
const SERVER_TIMEZONE         = 'Europe/Vienna';
const UTC_TIMEZONE            = 'UTC';
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

const HTTP_SUCCESS_CODES      = [200, 201, 204];

/**
 * Logs debug messages to output.
 */
function debugLog(string $message): void
{
    echo "[DEBUG] {$message}\n";
}

// Determine and log current timezone
$serverTz = new DateTimeZone(SERVER_TIMEZONE);
$utcTz = new DateTimeZone(UTC_TIMEZONE);
debugLog("Using timezone: " . SERVER_TIMEZONE);

// Validate webhook token
debugLog('Validating webhook token');
if (!isset($_GET['secret']) || $_GET['secret'] !== WEBHOOK_TOKEN) {
    http_response_code(403);
    exit('Invalid webhook token.');
}

// Read and parse JSON payload
debugLog('Reading payload');
$input   = file_get_contents('php://input');
$payload = json_decode($input);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit('Invalid JSON payload.');
}

// Process webhook types resourceLog_created and resourceLog_updated only
$type = $payload->type ?? null;
if (!in_array($type, ['resourceLog_created', 'resourceLog_updated'], true)) {
    http_response_code(202);
    exit("Webhook type {$type} not processed.");
}

// Process webhooks with log payload only
if (!isset($payload->details->log)) {
    http_response_code(202);
    exit('Missing log data in payload.');
}

$logEntry = $payload->details->log;
$logId    = (int) $logEntry->id;
debugLog("Processing resource log ID: {$logId}");

// Determine and validate allowed resources IDs
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

// Skip ongoing activities
if (!isset($logEntry->stopType)) {
    http_response_code(202);
    exit('Event is not a stop; nothing to do.');
}

// Skip activities which have already been processed
if (isset($logEntry->metadata->{'Formlabs Printjob'})) {
    debugLog("Metadata already contains 'Formlabs Printjob'; skipping billing.");
    http_response_code(202);
    exit('Job already processed - skipping.');
}

// Fetch resource metadata from Fabman
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

// Authenticate with Formlabs API
$accessToken = formLabsLogin(FORMLABS_CLIENT_ID, FORMLABS_USER, FORMLABS_PASSWORD);
debugLog('Obtained Formlabs access token: ' . ($accessToken !== null ? 'yes' : 'no'));
if ($accessToken === null) {
    http_response_code(500);
    exit('Server error: Formlabs authentication failed.');
}

// Determine Fabman activity timeframe
$activityStart = new DateTimeImmutable($logEntry->createdAt);
$activityEnd   = new DateTimeImmutable($logEntry->stoppedAt);

// Retrieve printjobs from Formlabs which have been finished during the activity period
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

// No print jobs during this activity period
if ($printCount === 0) {
    debugLog('No Formlabs prints during this activity');
    /*
    * Delete the Fabman activity that triggered the webhook.
    * Reason: If the activity was taken over by another Fabman member during the print job,
    * the subsequent activity (which is relevant for billing) cannot be adjusted to match
    * the duration of the print job, as it would overlap with this activity.
    */
    $del = callFabmanApi('DELETE', "resource-logs/{$logId}");
    if (in_array($del['http_code'], HTTP_SUCCESS_CODES, true)) {
        http_response_code(202);
        exit("Activity $logId deleted");
    } else {
        if ($del['http_code'] == 404) {
            exit("Activity $logId doesn't exist anymore - no need to delete it");
        } else {
            http_response_code(500);
            exit(sprintf(
                "Failed to delete Activity %d: HTTP %d, Response: %s",
                $logId,
                $del['http_code'],
                json_encode($del['data'])
            ));
        }
    }

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

        try {
            $pjStart = new DateTimeImmutable($printJob->print_started_at);
            $pjEnd   = getPrintJobEndDate($printJob);
        } catch (Exception $e) {
            debugLog("No valid end date for PrintJob {$printJob->guid}, using current time as fallback");
            $pjEnd = new DateTimeImmutable(); // fallback to now
        }

        $createdAtUtc = $pjStart->setTimezone($utcTz)->format('Y-m-d\TH:i:s\Z');
        $stoppedAtUtc = $pjEnd->setTimezone($utcTz)->format('Y-m-d\TH:i:s\Z');

        $timeRes = updateActivityTimestamps($logId, $createdAtUtc, $stoppedAtUtc);

        if (!in_array($timeRes['http_code'], HTTP_SUCCESS_CODES, true)) {
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

        try {
            $pjEnd = getPrintJobEndDate($printJob);
        } catch (Exception $e) {
            debugLog("No valid end date for PrintJob {$printJob->guid}, using current time as fallback");
            $pjEnd = new DateTimeImmutable(); // fallback to now
        }

        // Lokale Zeitstempel (serverTz)
        $createdAtLocal = $pjStart->setTimezone($serverTz)->format('Y-m-d H:i:s');
        $stoppedAtLocal = $pjEnd->setTimezone($serverTz)->format('Y-m-d H:i:s');

        debugLog("Trying to create activity for PrintJob {$printJob->id} with start: {$createdAtLocal} and end: {$stoppedAtLocal} (local time)");

        $createdAtUtc = $pjStart->setTimezone($utcTz)->format('Y-m-d\TH:i:s\Z');
        $stoppedAtUtc = $pjEnd->setTimezone($utcTz)->format('Y-m-d\TH:i:s\Z');
                    
        $newLogPayload = [
            'resource'  => $resourceId,
            'member'    => $memberId,
            'createdAt' => $createdAtUtc,
            'stoppedAt' => $stoppedAtUtc,
        ];

        $createLog = callFabmanApi('POST', 'resource-logs', $newLogPayload);
        if ($createLog['http_code'] !== 201) {
            $errorMessage = isset($createLog['data']->message) ? $createLog['data']->message : 'Unknown error';
            debugLog("Failed to create new activity for job {$printJob->id}. Error: {$errorMessage}");
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
    #global $currentTimezone;
    global $serverTz;

    // Parse timestamps
    $printStartedAt = isset($printJob->print_started_at)
        ? new DateTimeImmutable($printJob->print_started_at)
        : null;

    try {
        $printFinishedAt = getPrintJobEndDate($printJob);
    } catch (Exception $e) {
        debugLog('Print job missing end timestamp and no fallback available; skipping.');
        http_response_code(202);
        exit('Missing Formlabs print end timestamp.');
    }

    // Validate timestamps
    if (!$printStartedAt || !$printFinishedAt) {
        debugLog('Print job missing timestamps; skipping.');
        http_response_code(202);
        exit('Missing Formlabs print timestamps.');
    }

    if (
        $activityStart->getTimestamp() > $printStartedAt->getTimestamp() ||
        $printStartedAt->getTimestamp() > $printFinishedAt->getTimestamp() ||
        $printFinishedAt->getTimestamp() > $activityEnd->getTimestamp()
    ) {
        debugLog(sprintf(
            "Invalid timestamp order: activityStart=%s, printStartedAt=%s, printFinishedAt=%s, activityEnd=%s",
            $activityStart->format('Y-m-d H:i:s'),
            $printStartedAt->format('Y-m-d H:i:s'),
            $printFinishedAt->format('Y-m-d H:i:s'),
            $activityEnd->format('Y-m-d H:i:s')
        ));
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
        ->setTimezone($serverTz)
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
    if (!in_array($metaRes['http_code'], HTTP_SUCCESS_CODES, true)) {
        http_response_code(500);
        exit(sprintf(
            "Failed to update metadata: HTTP %d, Response: %s",
            $metaRes['http_code'],
            json_encode($metaRes['data'])
        ));
    }

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
    if (!in_array($response['http_code'], HTTP_SUCCESS_CODES, true)) {
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
    global $serverTz;

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
            if (empty($pj->print_started_at)) {
                debugLog("Skip incomplete print job {$pj->name}");
                continue;
            }

            try {
                $end = getPrintJobEndDate($pj);
            } catch (Exception $e) {
                debugLog("Invalid date in job {$pj->guid}: {$e->getMessage()}");
                continue;
            }

            if ($end >= $from && $end <= $to) {
                $all[] = $pj;
            } else {     
                debugLog(sprintf(
                    "Print job end timestamp %s not within activity (%s and %s)",
                    $end->setTimezone($serverTz)->format(DateTime::ATOM),
                    $from->setTimezone($serverTz)->format(DateTime::ATOM),
                    $to->setTimezone($serverTz)->format(DateTime::ATOM)
                ));               
            }
        }

        $fetched = count($data->results);
        $page++;
    } while ($fetched === $perPage);

    return $all;
}

/**
 * Returns the end date of a print job.
 * - Uses print_finished_at if available.
 * - Otherwise uses print_run_success->created_at if print_run_success is SUCCESS.
 * - Throws an exception if no valid end date is found.
 *
 * @throws Exception
 */
function getPrintJobEndDate(object $printJob): DateTimeImmutable
{
    if (!empty($printJob->print_finished_at)) {
        return new DateTimeImmutable($printJob->print_finished_at);
    }

    if (
        isset($printJob->print_run_success) &&
        isset($printJob->print_run_success->print_run_success) &&
        $printJob->print_run_success->print_run_success === "SUCCESS" &&
        !empty($printJob->print_run_success->created_at)
    ) {
        return new DateTimeImmutable($printJob->print_run_success->created_at);
    }

    throw new Exception("No valid end date for PrintJob {$printJob->guid}");
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
 * Shrinks an activity to new start/stop timestamps, with retries on lock conflicts.
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

        // 2) Attempt to update timestamps only
        $payload = [
            'createdAt'   => $createdAt,
            'stoppedAt'   => $stoppedAt,
            'lockVersion' => $lockVersion,
        ];

        $put = callFabmanApi('PUT', "resource-logs/{$resourceLogId}", $payload);

        if (in_array($put['http_code'], HTTP_SUCCESS_CODES, true)) {
            debugLog(sprintf(
                "Updated activity #%d timestamps: createdAt=%s, stoppedAt=%s",
                $resourceLogId,
                $createdAt,
                $stoppedAt
            ));
            return $put;
        }

        // If necessary, wait and retry
        debugLog("Timestamp update attempt #{$attempt} failed (HTTP {$put['http_code']}); retrying");
        usleep(200000);

    } while ($attempt < $maxAttempts);

    return $put;
}



/**
 * Updates only the metadata of an activity, with retries on lock conflicts.
 */
function updateActivityMetadata(int $resourceLogId, array $newMetadata, bool $merge = true): array
{
    $maxAttempts = 5;
    $attempt     = 0;
    do {
        $attempt++;
        // 1) Retrieve current log with metadata and lockVersion
        $get = callFabmanApi('GET', "resource-logs/{$resourceLogId}");
        if ($get['http_code'] !== 200) {
            return $get;
        }
        $lockVersion      = $get['data']->lockVersion ?? null;
        $existingMetadata = (array)($get['data']->metadata ?? []);
        $payloadMeta      = $merge
            ? array_merge($existingMetadata, $newMetadata)
            : $newMetadata;

        // 2) Attempt to update metadata only
        $payload = [
            'metadata'    => $payloadMeta,
            'lockVersion' => $lockVersion,
        ];
        $put = callFabmanApi('PUT', "resource-logs/{$resourceLogId}", $payload);

        if (in_array($put['http_code'], HTTP_SUCCESS_CODES, true)) {
            return $put;
        }

        debugLog("Metadata update attempt #{$attempt} failed (HTTP {$put['http_code']}); retrying");
        usleep(200000);
    } while ($attempt < $maxAttempts);

    return $put;
}

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
 * Checks whether the time ranges from the webhook payload and the Formlabs job match exactly.
 *
 * @param object $logEntry  The object from the webhook, with ->createdAt and ->stoppedAt (ISO strings)
 * @param object $printJob  The Formlabs job object, with ->print_started_at and ->print_finished_at
 * @return bool             True if start and end times match exactly to the second
 */
function timerangesMatch(object $logEntry, object $printJob): bool
{
    global $serverTz, $utcTz;

    if (
        empty($logEntry->createdAt) ||
        empty($logEntry->stoppedAt) ||
        empty($printJob->print_started_at)
    ) {
        return false;
    }

    try {
        $logStart   = new DateTimeImmutable($logEntry->createdAt);
        $logEnd     = new DateTimeImmutable($logEntry->stoppedAt);
        $printStart = new DateTimeImmutable($printJob->print_started_at);
        $printEnd   = getPrintJobEndDate($printJob);

        debugLog(sprintf(
            "Comparing timeranges (server timezone): logStart=%s, logEnd=%s | printStart=%s, printEnd=%s",
            $logStart->setTimezone($serverTz)->format(DateTime::ATOM),
            $logEnd->setTimezone($serverTz)->format(DateTime::ATOM),
            $printStart->setTimezone($serverTz)->format(DateTime::ATOM),
            $printEnd->setTimezone($serverTz)->format(DateTime::ATOM)
        ));

        // Normalize to UTC for accurate comparison
        $logStartUtc   = $logStart->setTimezone($utcTz);
        $logEndUtc     = $logEnd->setTimezone($utcTz);
        $printStartUtc = $printStart->setTimezone($utcTz);
        $printEndUtc   = $printEnd->setTimezone($utcTz);

        return $logStartUtc->getTimestamp() === $printStartUtc->getTimestamp()
            && $logEndUtc->getTimestamp() === $printEndUtc->getTimestamp();

    } catch (Exception $e) {
        return false;
    }
}

