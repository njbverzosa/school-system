<?php
// API_SYSTEM/login.php — verify credentials and redirect by user_type
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../DB_Conn/config.php';

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
$clientToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($clientToken) || !hash_equals($sessionToken, $clientToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh and try again.']);
    exit;
}

/* Rate limit */
$now = time();
$_SESSION['login_attempts'] = array_values(array_filter(
    $_SESSION['login_attempts'] ?? [],
    fn($t) => is_int($t) && ($now - $t) < 600
));
if (count($_SESSION['login_attempts']) >= 10) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Masyadong maraming pagsubok. Subukan muli mamaya.']);
    exit;
}
$_SESSION['login_attempts'][] = $now;

/* Input */
$accountNumber = trim((string) ($_POST['account_number'] ?? ''));
$pinPlain = trim((string) ($_POST['pin'] ?? ''));

if ($accountNumber === '' || $pinPlain === '') {
    echo json_encode(['success' => false, 'message' => 'Ilagay ang account number at PIN.']);
    exit;
}

/* Search every table */
$tables = [
    'student' => 'students',
    'teacher' => 'teachers',
    'staff' => 'faculties_staff',
    'admin' => 'admins',
];

$foundUser = null;
$foundType = null;

foreach ($tables as $type => $table) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE account_number = ? LIMIT 1");
        $stmt->execute([$accountNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $foundUser = $row;
            $foundType = $type;
            break;
        }
    } catch (PDOException $e) {
        error_log("login lookup failed on {$table}: " . $e->getMessage());
        continue;
    }
}

if (!$foundUser) {
    echo json_encode([
        'success' => false,
        'message' => 'Mali ang account number o PIN.',
    ]);
    exit;
}

/* Verify PIN */
$storedHash = (string) ($foundUser['pin'] ?? '');
if ($storedHash === '' || !password_verify($pinPlain, $storedHash)) {
    echo json_encode([
        'success' => false,
        'message' => 'Mali ang account number o PIN.',
    ]);
    exit;
}

/* Resolve user_type */
$userType = trim((string) ($foundUser['user_type'] ?? ''));
if ($userType === '') {
    $userType = $foundType;
}
$userType = strtolower($userType);

$allowedTypes = ['student', 'teacher', 'staff', 'admin'];
if (!in_array($userType, $allowedTypes, true)) {
    echo json_encode(['success' => false, 'message' => 'Hindi wastong uri ng account.']);
    exit;
}

/* ------------------------------------------------------------
   Redirect by user type
   ------------------------------------------------------------ */
if ($userType === 'student') {
    $enrollmentStatus = strtolower(trim((string) ($foundUser['enrollment_status'] ?? 'not enrolled')));

    /*
     * Redirect rules for students:
     *  - enrollment_status = "enrolled" → academic-information.php
     *  - everything else (pending, not enrolled, empty, unknown)
     *                                   → student-enrollment.php
     */
    if ($enrollmentStatus === 'enrolled') {
        $redirect = 'student/academic-information.php';
    } else {
        $redirect = 'student/student-enrollment.php';
    }
} elseif ($userType === 'admin') {
    $redirect = 'admin/dashboard.php';
} elseif ($userType === 'teacher') {
    $redirect = 'teacher/advisory-class.php';
} else {
    $redirect = 'staff/home.php';
}

/* Session */
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $foundUser['id'];
$_SESSION['account_number'] = $foundUser['account_number'];
$_SESSION['user_type'] = $userType;
$_SESSION['full_name'] = trim(
    ($foundUser['first_name'] ?? '') . ' ' .
    ($foundUser['middle_name'] ?? '') . ' ' .
    ($foundUser['surname'] ?? '')
);
$_SESSION['logged_in_at'] = time();

echo json_encode([
    'success' => true,
    'message' => 'Matagumpay ang pag-login!',
    'redirect' => $redirect,
    'user' => [
        'account_number' => $foundUser['account_number'],
        'full_name' => $_SESSION['full_name'],
        'user_type' => $userType,
    ],
]);