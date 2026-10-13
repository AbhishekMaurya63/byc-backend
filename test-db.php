
<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';

try {
    $row = $pdo->query('SELECT DATABASE() AS database_name')->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'Database connection successful.',
        'database' => $row['database_name']
    ]);
} catch (Throwable $e) {
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database test failed.'
    ]);
}
