<?php
// API/admin-settings.php — Backend for toggling school-wide settings.
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

/* ------------------------------------------------------------
   HELPERS
   ------------------------------------------------------------ */
function flash(string $key, string $msg): void
{
    $_SESSION['flash'][$key] = $msg;
}

function redirectDashboard(): void
{
    header('Location: ../admin/dashboard.php');
    exit;
}

/* ------------------------------------------------------------
   1. AUTHENTICATION — Must be Admin
   ------------------------------------------------------------ */
$isAdmin = false;
if (!empty($_SESSION['account_number']) && is_string($_SESSION['account_number'])) {
    $accountNumber = trim($_SESSION['account_number']);

    if (preg_match('/^26_[A-Z]{1,8}_\d{1,7}$/', $accountNumber)) {
        try {
            $stmt = $pdo->prepare("
                SELECT id 
                FROM admins
                WHERE account_number = ?
                  AND user_type = 'admin'
                LIMIT 1
            ");
            $stmt->execute([$accountNumber]);
            if ($stmt->fetchColumn()) {
                $isAdmin = true;
            }
        } catch (PDOException $e) {
            error_log("admin-settings: auth check failed: " . $e->getMessage());
        }
    }
}

if (!$isAdmin) {
    flash('admin_error', 'Unauthorized access. Please log in as an admin.');
    redirectDashboard();
}

/* ------------------------------------------------------------
   2. CSRF PROTECTION
   ------------------------------------------------------------ */
$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    flash('admin_error', 'Invalid security token. Please try again.');
    redirectDashboard();
}

/* ------------------------------------------------------------
   3. VALIDATE INPUT
   ------------------------------------------------------------ */
$action = $_POST['action'] ?? '';
$newStatus = $_POST['new_status'] ?? null;

if ($action !== 'toggle_enrollment') {
    flash('admin_error', 'Invalid action requested.');
    redirectDashboard();
}

// Ensure the status is strictly '0' or '1'
if ($newStatus !== '0' && $newStatus !== '1') {
    flash('admin_error', 'Invalid enrollment status value.');
    redirectDashboard();
}

/* ------------------------------------------------------------
   4. UPDATE THE STUDENTS TABLE
   ------------------------------------------------------------ */
try {
    // Update ALL students' academic_status at once
    $stmt = $pdo->prepare("
        UPDATE students 
        SET enrollment_status = :val
    ");
    $stmt->execute([':val' => $newStatus]);

    // Success message
    $statusText = ($newStatus === '1') ? 'OPENED' : 'CLOSED';
    flash('admin_success', "The enrollment window has been successfully {$statusText} for all students.");

} catch (PDOException $e) {
    error_log("admin-settings: update failed: " . $e->getMessage());
    flash('admin_error', 'Failed to update the enrollment status. Please try again.');
}

/* ------------------------------------------------------------
   5. REDIRECT BACK
   ------------------------------------------------------------ */
redirectDashboard();