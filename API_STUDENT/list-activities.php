<?php
// API_STUDENT/list-activities.php
// Returns the logged-in student's submissions as JSON
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../DB_Conn/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['account_number']) || empty($_SESSION['user_type'])
    || strtolower((string) $_SESSION['user_type']) !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$accountNumber = trim((string) $_SESSION['account_number']);

try {
    $stmt = $pdo->prepare("
        SELECT id, account_number, grade_level, section, subject, file_name, type, status, modified_at
        FROM submitted_activities
        WHERE account_number = ?
        ORDER BY modified_at DESC, id DESC
    ");
    $stmt->execute([$accountNumber]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['success' => true, 'rows' => $rows]);
} catch (PDOException $e) {
    error_log("list-activities failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
}