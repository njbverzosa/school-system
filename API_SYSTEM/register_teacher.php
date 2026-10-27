<?php
// API_SYSTEM/register_teacher.php — teacher registration
// Account number format: 26<INITIALS>_T + 3 digits (e.g. 26ENHS_T001)
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// CSRF
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

// Rate limit
$now = time();
$_SESSION['reg_attempts'] = array_values(array_filter(
    $_SESSION['reg_attempts'] ?? [],
    fn($t) => is_int($t) && ($now - $t) < 600
));
if (count($_SESSION['reg_attempts']) >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Masyadong maraming pagsubok. Subukan muli mamaya.']);
    exit;
}
$_SESSION['reg_attempts'][] = $now;

/* Helper: initials */
function schoolInitials(string $name): string
{
    $name  = preg_replace('/\x{00A0}/u', ' ', $name);
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach ($words as $word) {
        $first = mb_strtoupper(mb_substr($word, 0, 1));
        if (preg_match('/^[A-Z]$/', $first)) {
            $initials .= $first;
        }
    }
    return $initials;
}

// Input
$firstName     = trim((string) ($_POST['first_name'] ?? ''));
$middleName    = trim((string) ($_POST['middle_name'] ?? ''));
$surname       = trim((string) ($_POST['surname'] ?? ''));
$pinPlain      = trim((string) ($_POST['pin'] ?? ''));
$schoolAddr    = trim((string) ($_POST['school_address'] ?? ''));
$sex           = trim((string) ($_POST['sex'] ?? ''));
$birthDate     = trim((string) ($_POST['birth_date'] ?? ''));
$birthPlace    = trim((string) ($_POST['birth_place'] ?? ''));
$address       = trim((string) ($_POST['address'] ?? ''));
$contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
$position      = trim((string) ($_POST['position'] ?? ''));
$department    = trim((string) ($_POST['department'] ?? ''));

// Validation
$errors = [];
$namePattern = "/^[\p{L}][\p{L} .'\-]{1,59}$/u";

if ($firstName === '')
    $errors['first_name'] = 'First name is required.';
elseif (!preg_match($namePattern, $firstName))
    $errors['first_name'] = 'First name must be letters only (2-60 chars).';

if ($middleName === '')
    $errors['middle_name'] = 'Middle name is required.';
elseif (!preg_match($namePattern, $middleName))
    $errors['middle_name'] = 'Middle name must be letters only (2-60 chars).';

if ($surname === '')
    $errors['surname'] = 'Surname is required.';
elseif (!preg_match($namePattern, $surname))
    $errors['surname'] = 'Surname must be letters only (2-60 chars).';

if (!preg_match('/^\d{6}$/', $pinPlain))
    $errors['pin'] = 'PIN must be exactly 6 digits.';

if ($schoolAddr === '')
    $errors['school_address'] = 'Please choose a school.';

if (!in_array($sex, ['Male', 'Female'], true))
    $errors['sex'] = 'Please choose a sex.';

$computedAge = null;
if ($birthDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    $errors['birth_date'] = 'Please provide a valid birth date.';
} else {
    $d = DateTime::createFromFormat('Y-m-d', $birthDate);
    if (!$d || $d->format('Y-m-d') !== $birthDate) {
        $errors['birth_date'] = 'Birth date is not valid.';
    } else {
        $computedAge = $d->diff(new DateTime('today'))->y;
        if ($computedAge < 1 || $computedAge > 120)
            $errors['birth_date'] = 'Birth date implies an invalid age.';
    }
}

if ($birthPlace === '') $errors['birth_place'] = 'Birth place is required.';
if ($address === '')    $errors['address']     = 'Address is required.';

if ($contactNumber === '' || !preg_match('/^[0-9+\-\s]{7,15}$/', $contactNumber))
    $errors['contact_number'] = 'Contact number must be 7–15 digits.';

if ($position === '')
    $errors['position'] = 'Please choose a position.';

if ($department === '')
    $errors['department'] = 'Please choose a department.';

if ($errors) {
    echo json_encode(['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors]);
    exit;
}

// Verify school + compute prefix
$schoolName   = '';
$schoolPrefix = '';

try {
    $stmt = $pdo->prepare("SELECT school_name FROM schools WHERE school_name = ? LIMIT 1");
    $stmt->execute([$schoolAddr]);
    $schoolName = trim((string) ($stmt->fetchColumn() ?: ''));

    if ($schoolName === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Unknown school selected.',
            'errors'  => ['school_address' => 'Unknown school.'],
        ]);
        exit;
    }

    $initials = schoolInitials($schoolName);

    if ($initials === '') {
        echo json_encode([
            'success' => false,
            'message' => 'School name has no usable initials.',
            'errors'  => ['school_address' => 'Invalid school name format.'],
        ]);
        exit;
    }

    // Teacher format: 26<INITIALS>_T
    $schoolPrefix = '26' . $initials . '_T';
} catch (PDOException $e) {
    error_log("register_teacher: school verify failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Try again later.']);
    exit;
}

// Account number: 26<INITIALS>_T + 3 digits (001 – 999)
function generateTeacherAccountNumber(PDO $pdo, string $prefix): string
{
    $stmt = $pdo->prepare("SELECT account_number FROM teachers WHERE account_number LIKE ? ORDER BY account_number DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $nextNum = 1;
    if ($last) {
        $lastNum = (int) substr($last, strlen($prefix));
        $nextNum = $lastNum + 1;
    }

    for ($i = $nextNum; $i <= 999; $i++) {
        $acc = $prefix . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT 1 FROM teachers WHERE account_number = ? LIMIT 1");
        $check->execute([$acc]);
        if (!$check->fetchColumn()) return $acc;
    }

    throw new RuntimeException('Teacher account pool exhausted.');
}

try {
    $accountNumber = generateTeacherAccountNumber($pdo, $schoolPrefix);
} catch (Throwable $e) {
    error_log("register_teacher: id generation failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not generate account. Try again.']);
    exit;
}

// Hash PIN + keep plaintext copy in pin_text
$pinHash = password_hash($pinPlain, PASSWORD_DEFAULT);
$pinText = (int) $pinPlain; // e.g. 112233

// Insert
$sql = "INSERT INTO teachers (
            account_number, pin, pin_text,
            first_name, middle_name, surname,
            age, sex, birth_date, birth_place, address, contact_number,
            registered_date, profile_account, user_type,
            school_name, position, department
        ) VALUES (
            :account_number, :pin, :pin_text,
            :first_name, :middle_name, :surname,
            :age, :sex, :birth_date, :birth_place, :address, :contact_number,
            :registered_date, :profile_account, 'teacher',
            :school_name, :position, :department
        )";

$params = [
    ':account_number'  => $accountNumber,
    ':pin'             => $pinHash,
    ':pin_text'        => $pinText,
    ':first_name'      => $firstName,
    ':middle_name'     => $middleName,
    ':surname'         => $surname,
    ':age'             => (int) $computedAge,
    ':sex'             => $sex,
    ':birth_date'      => $birthDate,
    ':birth_place'     => $birthPlace,
    ':address'         => $address,
    ':contact_number'  => $contactNumber,
    ':registered_date' => date('F j, Y g:iA'),
    ':profile_account' => 'profile-image.jpg',
    ':school_name'     => $schoolAddr,
    ':position'        => $position,
    ':department'      => $department,
];

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'message'        => 'Matagumpay ang pagrehistro!',
        'account_number' => $accountNumber,
        'user_type'      => 'teacher',
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("register_teacher insert failed: " . $e->getMessage());
    $msg = 'Registration failed. Please try again.';
    if ($e->getCode() === '23000')
        $msg = 'Duplicate account number detected. Please try again.';
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $msg]);
}