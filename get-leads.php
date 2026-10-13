
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

function queryString(string $key): string
{
    return trim((string) ($_GET[$key] ?? ''));
}

function validDate(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

try {
    require __DIR__ . '/db.php';

    // Pagination
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Filters
    $search = queryString('search');
    $city = queryString('city');
    $grade = queryString('grade');
    $source = queryString('source');

    $whatsappStatus = queryString('whatsappStatus');
    if ($whatsappStatus === '') {
        $whatsappStatus = queryString('whatsapp_status');
    }

    $emailStatus = queryString('emailStatus');
    if ($emailStatus === '') {
        $emailStatus = queryString('email_status');
    }

    $fromDate = queryString('fromDate');
    $toDate = queryString('toDate');

    // Validate date filters
    if (
        ($fromDate !== '' && !validDate($fromDate)) ||
        ($toDate !== '' && !validDate($toDate))
    ) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Dates must use YYYY-MM-DD format.'
        ]);
        exit;
    }

    if (
        $fromDate !== '' &&
        $toDate !== '' &&
        $fromDate > $toDate
    ) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'fromDate cannot be after toDate.'
        ]);
        exit;
    }

    // Build parameterized filters
    $conditions = [];
    $params = [];

    if ($search !== '') {
        $conditions[] = "(
            parent_name LIKE :search_parent
            OR student_name LIKE :search_student
            OR phone LIKE :search_phone
            OR email LIKE :search_email
            OR school LIKE :search_school
        )";

        $searchValue = '%' . $search . '%';

        $params['search_parent'] = $searchValue;
        $params['search_student'] = $searchValue;
        $params['search_phone'] = $searchValue;
        $params['search_email'] = $searchValue;
        $params['search_school'] = $searchValue;
    }

    if ($city !== '') {
        $conditions[] = 'city = :city';
        $params['city'] = $city;
    }

    if ($grade !== '') {
        $conditions[] = 'grade = :grade';
        $params['grade'] = $grade;
    }

    if ($source !== '') {
        $conditions[] = 'source = :source';
        $params['source'] = $source;
    }

    if ($whatsappStatus !== '') {
        $conditions[] = 'whatsapp_status = :whatsapp_status';
        $params['whatsapp_status'] = $whatsappStatus;
    }

    if ($emailStatus !== '') {
        $conditions[] = 'email_status = :email_status';
        $params['email_status'] = $emailStatus;
    }

    if ($fromDate !== '') {
        $conditions[] = 'created_at >= :from_date';
        $params['from_date'] = $fromDate . ' 00:00:00';
    }

    if ($toDate !== '') {
        $conditions[] = 'created_at < :to_date_exclusive';

        $nextDay = (new DateTimeImmutable($toDate))
            ->modify('+1 day')
            ->format('Y-m-d');

        $params['to_date_exclusive'] = $nextDay . ' 00:00:00';
    }

    $whereSql = $conditions
        ? ' WHERE ' . implode(' AND ', $conditions)
        : '';

    // Count matching leads
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM registrations' . $whereSql
    );

    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch matching leads
    $sql = "
        SELECT
            id,
            parent_name,
            country_code,
            phone,
            email,
            student_name,
            grade,
            school,
            city,
            profession,
            source,
            whatsapp_status,
            email_status,
            created_at,
            updated_at
        FROM registrations
        $whereSql
        ORDER BY created_at DESC, id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $leads = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'message' => 'Leads fetched successfully.',
        'filters' => [
            'search' => $search,
            'city' => $city,
            'grade' => $grade,
            'source' => $source,
            'whatsappStatus' => $whatsappStatus,
            'emailStatus' => $emailStatus,
            'fromDate' => $fromDate,
            'toDate' => $toDate
        ],
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit)
        ],
        'data' => $leads
    ], JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    error_log('Get leads failed: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to fetch leads.'
    ]);
}
