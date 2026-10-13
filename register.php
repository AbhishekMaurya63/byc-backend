
<?php

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');

// Allow requests from any website.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle browser preflight requests.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Allow only POST requests for registration.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}



function sendAiSensyWhatsApp(
    array $config,
    string $parentName,
    string $countryCode,
    string $phone,
    string $date,
    string $time,
    string $city
): array {
    // 1. Check cURL availability
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'PHP cURL is unavailable'
        ];
    }

    // 2. Validate AiSensy configuration
    $apiKey = trim((string) ($config['aisensy_api_key'] ?? ''));

    if ($apiKey === '' || $apiKey === 'YOUR_AISENSY_API_KEY') {
        return [
            'success' => false,
            'error' => 'AiSensy API key is not configured'
        ];
    }

    // 3. Normalize country code and phone number
    $countryDigits = preg_replace('/\D/', '', $countryCode) ?? '';
    $phoneDigits = preg_replace('/\D/', '', $phone) ?? '';

    if ($countryDigits === '' || $phoneDigits === '') {
        return [
            'success' => false,
            'error' => 'Invalid phone number or country code'
        ];
    }

    // Remove international prefix if the phone field includes it.
    if (str_starts_with($phoneDigits, '00')) {
        $phoneDigits = substr($phoneDigits, 2);
    }

    // Avoid adding the country code twice.
    if (str_starts_with($phoneDigits, $countryDigits)) {
        $destination = $phoneDigits;
    } else {
        $destination = $countryDigits . $phoneDigits;
    }

    // Basic international phone number length validation.
    if (strlen($destination) < 8 || strlen($destination) > 15) {
        return [
            'success' => false,
            'error' => 'Phone number length is invalid'
        ];
    }

    // 4. Find the correct WhatsApp group link (case-insensitive).
    $groupLinks = $config['whatsapp_group_links'] ?? [];
    $normalizedCity = strtolower(trim($city));
    $whatsappLink = '';

    foreach ($groupLinks as $groupCity => $groupLink) {
        if (strtolower(trim((string) $groupCity)) === $normalizedCity) {
            $whatsappLink = trim((string) $groupLink);
            break;
        }
    }

    if ($whatsappLink === '') {
        return [
            'success' => false,
            'error' => 'No WhatsApp group link configured for city: ' . $city
        ];
    }

    // 5. Validate campaign configuration.
    $campaignName = trim(
        (string) ($config['aisensy_campaign_name'] ?? 'BYC_Webinar_Registration')
    );

    if ($campaignName === '') {
        return [
            'success' => false,
            'error' => 'AiSensy campaign name is missing'
        ];
    }

    // 6. Prepare the template payload.
    $payload = [
        'apiKey' => $apiKey,
        'campaignName' => $campaignName,
        'destination' => $destination,
        'userName' => $config['aisensy_user_name']
            ?? 'Nayyar Book Your Campus Pvt Ltd',
        'templateParams' => [
            $parentName,    // {{1}} Parent name
            $date,          // {{2}} Event date
            $time,          // {{3}} Event time
            $city,          // {{4}} City
            $whatsappLink   // {{5}} WhatsApp group link
        ],
        'source' => 'new-landing-page form',
        'media' => new stdClass(),
        'buttons' => [],
        'carouselCards' => [],
        'location' => new stdClass(),
        'attributes' => new stdClass(),
        'paramsFallbackValue' => [
            'FirstName' => $parentName !== '' ? $parentName : 'user'
        ]
    ];

    // 7. Encode payload safely.
    $payloadJson = json_encode(
        $payload,
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($payloadJson === false) {
        error_log('AiSensy payload encoding failed: ' . json_last_error_msg());

        return [
            'success' => false,
            'error' => 'Unable to encode WhatsApp request'
        ];
    }

    // 8. Send request to AiSensy.
    $ch = curl_init(
        'https://backend.aisensy.com/campaign/t1/api/v2'
    );

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 9. Handle connection errors.
    if ($response === false) {
        error_log('AiSensy cURL error: ' . $curlError);

        return [
            'success' => false,
            'error' => 'AiSensy connection failed'
        ];
    }

    $responseData = json_decode($response, true);

    // 10. Handle HTTP errors.
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log(
            'AiSensy HTTP ' . $httpCode . ': ' . $response
        );

        return [
            'success' => false,
            'httpCode' => $httpCode,
            'error' => 'AiSensy returned HTTP ' . $httpCode
        ];
    }

    // 11. Return the API result.
    return [
        'success' => true,
        'httpCode' => $httpCode,
        'response' => $responseData ?? $response
    ];
}



function respond(int $status, array $data): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function field(array $data, string $key): string
{
    $value = $data[$key] ?? '';

    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}

// Decode JSON request
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);
}

// Read form fields
$parentName  = field($input, 'parentName');
$countryCode = field($input, 'countryCode');
$phone       = field($input, 'phone');
$email       = field($input, 'email');
$studentName = field($input, 'studentName');
$grade       = field($input, 'grade');
$school       = field($input, 'school');
$city         = field($input, 'city');
$profession   = field($input, 'profession');
$date         = field($input, 'date');
$time         = field($input, 'time');

// Required field validation
if (
    $parentName === '' ||
    $countryCode === '' ||
    $phone === '' ||
    $email === '' ||
    $studentName === '' ||
    $grade === '' ||
    $date === '' ||
    $time === ''
) {
    respond(422, [
        'success' => false,
        'message' => 'Please complete all required fields.'
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, [
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ]);
}

if (!function_exists('curl_init')) {
    error_log('Registration API: PHP cURL extension is unavailable.');

    respond(500, [
        'success' => false,
        'message' => 'Email service is not available on the server.'
    ]);
}

// Validate EmailJS configuration
$requiredConfig = [
    'emailjs_service_id',
    'emailjs_template_id',
    'emailjs_public_key'
];

foreach ($requiredConfig as $key) {
    if (
        !isset($config[$key]) ||
        !is_string($config[$key]) ||
        trim($config[$key]) === ''
    ) {
        error_log('Registration API: Missing config key: ' . $key);

        respond(500, [
            'success' => false,
            'message' => 'Email service configuration is incomplete.'
        ]);
    }
}

// 1. Save registration in MySQL
try {
    require __DIR__ . '/db.php';

    $sql = '
        INSERT INTO registrations (
            parent_name,
            country_code,
            phone,
            email,
            student_name,
            grade,
            school,
            city,
            profession
        ) VALUES (
            :parent_name,
            :country_code,
            :phone,
            :email,
            :student_name,
            :grade,
            :school,
            :city,
            :profession
        )
    ';

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        'parent_name'  => $parentName,
        'country_code' => $countryCode,
        'phone'        => $phone,
        'email'        => $email,
        'student_name' => $studentName,
        'grade'        => $grade,
        'school'       => $school !== '' ? $school : null,
        'city'         => $city !== '' ? $city : null,
        'profession'   => $profession !== '' ? $profession : null,
    ]);

    $registrationId = (int) $pdo->lastInsertId();

} catch (Throwable $e) {
    error_log(
        'Registration save failed: ' .
        get_class($e) . ': ' . $e->getMessage()
    );

    respond(500, [
        'success' => false,
        'message' => 'Unable to save registration.'
    ]);
}

$whatsappResult = sendAiSensyWhatsApp(
    $config,
    $parentName,
    $countryCode,
    $phone,
    $date,
    $time,
    $city
);

$whatsappSent = $whatsappResult['success'];

if (!$whatsappSent) {
    error_log(
        'WhatsApp failed for registration ' . $registrationId
        . ': ' . ($whatsappResult['error'] ?? 'Unknown error')
    );
}

// 2. Send confirmation email using EmailJS
$emailPayload = [
    'service_id' => $config['emailjs_service_id'],
    'template_id' => $config['emailjs_template_id'],
    'user_id' => $config['emailjs_public_key'],
    'template_params' => [
        'to_email' => $email,
        'to_name'  => $studentName,
        'city'     => $city !== '' ? $city : 'Bangalore',
        'date'     => $date,
        'time'     => $time,
    ],
];

$payloadJson = json_encode(
    $emailPayload,
    JSON_INVALID_UTF8_SUBSTITUTE
);

if ($payloadJson === false) {
    error_log('EmailJS payload encoding failed.');

    respond(201, [
        'success' => true,
        'message' => 'Registration saved, but the confirmation email could not be sent.',
        'registrationId' => $registrationId,
        'emailSent' => false
    ]);
}

$ch = curl_init(
    'https://api.emailjs.com/api/v1.0/email/send'
);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Origin: https://bookyourcampus.com',
],
    CURLOPT_POSTFIELDS => $payloadJson,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
]);

$emailResponse = curl_exec($ch);
$emailError = curl_error($ch);
$emailStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

$emailSent = (
    $emailResponse !== false &&
    $emailStatus >= 200 &&
    $emailStatus < 300
);

if (!$emailSent) {
    // Keep the detailed error in server logs, not the public response.
    error_log(
        'EmailJS failed for registration ' . $registrationId .
        '; HTTP status: ' . $emailStatus .
        '; cURL error: ' . $emailError .
        '; response: ' . (string) $emailResponse
    );
}

try {
    $statusStmt = $pdo->prepare("
        UPDATE registrations
        SET email_status = :email_status,
            whatsapp_status = :whatsapp_status
        WHERE id = :id
    ");

    $statusStmt->execute([
        'email_status' => $emailSent ? 'sent' : 'failed',
        'whatsapp_status' => $whatsappSent ? 'sent' : 'failed',
        'id' => $registrationId
    ]);
} catch (Throwable $e) {
    error_log(
        'Failed to update notification statuses for registration '
        . $registrationId . ': ' . $e->getMessage()
    );
}

respond(201, [
    'success' => true,
    'message' => 'Registration processed.',
    'registrationId' => $registrationId,
    'emailSent' => $emailSent,
    'whatsappSent' => $whatsappSent
]);
