<?php
// API_STUDENT/delete-activity.php
// Deletes one submitted_activities row (owner-only) + the file on disk.
// Request: POST JSON { "id": 123 }
// Requires: student session + CSRF token (X-CSRF-Token header)
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

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

require_once __DIR__ . '/../DB_Conn/config.php';

/* Method check */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

/* Must be logged in */
if (empty($_SESSION['account_number']) || empty($_SESSION['user_type'])
    || strtolower((string) $_SESSION['user_type']) !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

/* CSRF */
$sessionToken = $_SESSION['csrf_token'] ?? '';
$clientToken  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionToken === '' || !is_string($clientToken) || !hash_equals($sessionToken, $clientToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

/* Parse JSON body */
$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body.']);
    exit;
}

$id = isset($payload['id']) ? (int) $payload['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing submission id.']);
    exit;
}

$accountNumber = trim((string) $_SESSION['account_number']);

try {
    /* Fetch row — must belong to the logged-in student */
    $stmt = $pdo->prepare("
        SELECT id, account_number, file_name, type
        FROM submitted_activities
        WHERE id = ?
          AND account_number = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $accountNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Submission not found or not yours.']);
        exit;
    }

    /* Delete DB row */
    $del = $pdo->prepare("
        DELETE FROM submitted_activities
        WHERE id = ?
          AND account_number = ?
        LIMIT 1
    ");
    $del->execute([$id, $accountNumber]);

    if ($del->rowCount() < 1) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not delete submission.']);
        exit;
    }

    /* Delete the file on disk (best-effort) */
    $fileName = trim((string) ($row['file_name'] ?? ''));
    if ($fileName !== '') {
        $path = __DIR__ . '/../uploads/submitted_activities/' . basename($fileName);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Submission deleted.']);
    exit;
} catch (PDOException $e) {
    error_log("delete-activity failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}