
<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';

$sql = "
CREATE TABLE IF NOT EXISTS registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_name VARCHAR(150) NOT NULL,
    country_code VARCHAR(10) NOT NULL,
    phone VARCHAR(25) NOT NULL,
    email VARCHAR(190) NOT NULL,
    student_name VARCHAR(150) NOT NULL,
    grade VARCHAR(50) NOT NULL,
    school VARCHAR(190) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    profession VARCHAR(100) DEFAULT NULL,
    source VARCHAR(100) NOT NULL DEFAULT 'BYC Website',
    whatsapp_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    email_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_phone (phone),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $pdo->exec($sql);

    echo json_encode([
        'success' => true,
        'message' => 'Registrations table created successfully.'
    ]);
} catch (PDOException $e) {
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Could not create registrations table.'
    ]);
}
