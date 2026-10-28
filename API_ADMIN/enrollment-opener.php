<?php
// API_ADMIN/enrollment-settings.php
// Handles admin enrollment toggle + single-student access updates.
// POST-only. CSRF required. Admin session required.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

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

/* Method guard */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$sessionToken = $_SESSION['csrf_token'];
$clientToken  = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($clientToken) || !hash_equals($sessionToken, $clientToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh and try again.']);
    exit;
}

/* Auth — admin */
$isAdmin = false;
$adminUser = null;

if (!empty($_SESSION['account_number']) && is_string($_SESSION['account_number'])) {
    $accountNumber = trim($_SESSION['account_number']);

    if (preg_match('/^26[A-Z]{1,8}_A\d{1,7}$/', $accountNumber)) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, account_number, first_name, surname, school_name
                FROM admins
                WHERE account_number = ?
                  AND user_type = 'admin'
                LIMIT 1
            ");
            $stmt->execute([$accountNumber]);
            $adminUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("enrollment-settings: admin lookup failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error. Try again later.']);
            exit;
        }
    }
}

if (!$adminUser) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

/* Flash helper */
function setFlash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash']['admin_' . $type] = $message;
}

/* Route by action */
$action = trim((string) ($_POST['action'] ?? ''));

switch ($action) {

    /* ============================================================
       ACTION 1: Toggle enrollment access for Old Students
       enrollment_access: 1 = open, 0 = closed
       ============================================================ */
    case 'toggle_enrollment': {
        $newStatusRaw = trim((string) ($_POST['new_status'] ?? ''));

        if (!in_array($newStatusRaw, ['0', '1'], true)) {
            setFlash('error', 'Invalid enrollment status value.');
            header('Location: ../admin/dashboard.php');
            exit;
        }

        $targetAccess = (int) $newStatusRaw;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE students
                SET enrollment_access = ?
            ");
            $stmt->execute([$targetAccess, $targetAccess]);
            $affected = $stmt->rowCount();

            $pdo->commit();

            setFlash(
                'success',
                $targetAccess === 1
                    ? "Enrollment is now OPEN for All Students."
                    : "Enrollment is now CLOSED for All Students."
            );
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("enrollment-settings: toggle_enrollment failed: " . $e->getMessage());
            setFlash('error', 'Could not update enrollment access. Please try again.');
        }

        header('Location: ../admin/dashboard.php');
        exit;
    }

    /* ============================================================
       ACTION 2: Set enrollment_access for a single student (1 or 0)
       ============================================================ */
    case 'update_student_status': {
        $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
        $newStatusCode = trim((string) ($_POST['new_status'] ?? ''));

        if ($accountNumber === '') {
            setFlash('error', 'Missing student account number.');
            header('Location: ../admin/dashboard.php');
            exit;
        }

        if (!in_array($newStatusCode, ['0', '1'], true)) {
            setFlash('error', 'Invalid enrollment access value. Must be 0 or 1.');
            header('Location: ../admin/dashboard.php');
            exit;
        }

        $targetAccess = (int) $newStatusCode;

        try {
            $stmt = $pdo->prepare("
                UPDATE students
                SET enrollment_access = ?
                WHERE account_number = ?
            ");
            $stmt->execute([$targetAccess, $accountNumber]);

            if ($stmt->rowCount() === 0) {
                setFlash('error', 'Student not found or access unchanged.');
            } else {
                setFlash(
                    'success',
                    "Student {$accountNumber} enrollment access is now " .
                    ($targetAccess === 1 ? 'OPEN' : 'CLOSED') . '.'
                );
            }
        } catch (PDOException $e) {
            error_log("enrollment-settings: update_student_status failed: " . $e->getMessage());
            setFlash('error', 'Could not update enrollment access. Please try again.');
        }

        header('Location: ../admin/dashboard.php');
        exit;
    }

    /* Unknown action */
    default: {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }
}