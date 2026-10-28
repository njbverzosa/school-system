<?php
// API_ADMIN/enroll-students.php
// Handles enrolling a New Student / Transferee.
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
            error_log("enroll-students: admin lookup failed: " . $e->getMessage());
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

/* ------------------------------------------------------------
   Whitelists
   ------------------------------------------------------------ */
$SECTION_OPTIONS = [
    'Ilang-Ilang', 'Dahlia', 'Rosal', 'Sampaguita',
    'Acacia', 'Yakal', 'Molave', 'Mahogany',
    'Mabini', 'Rizal', 'Jacinto', 'Bonifacio',
    'Emerald', 'Diamond', 'Jade',
    'Home Economics - Pasteur', 'Einstein', 'Socrates', 'Franklin',
    'Automotive Servicing', 'TVL - HE', 'STEM', 'Humanities & Social Science'
];

function currentAcademicYear(): string
{
    $y = (int) date('Y');
    return ((int) date('n') < 6) ? (($y - 1) . '-' . $y) : ($y . '-' . ($y + 1));
}

function columnExists(PDO $pdo, string $table, string $col): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->execute([$table, $col]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("columnExists failed: " . $e->getMessage());
        return false;
    }
}

/* ------------------------------------------------------------
   Route by action
   ------------------------------------------------------------ */
$action = trim((string) ($_POST['action'] ?? ''));

if ($action !== '' && $action !== 'enroll_student') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

/* ------------------------------------------------------------
   ACTION: Enroll a New Student / Transferee
   ------------------------------------------------------------ */
$accountNumber = trim((string) ($_POST['account_number'] ?? ''));
$gradeLevelRaw = trim((string) ($_POST['grade_level'] ?? ''));
$sectionRaw    = trim((string) ($_POST['section'] ?? ''));

if ($accountNumber === '') {
    setFlash('error', 'Missing student account number.');
    header('Location: ../admin/dashboard.php');
    exit;
}

if (!preg_match('/^(?:[7-9]|1[0-2])$/', $gradeLevelRaw)) {
    setFlash('error', 'Please select a grade level between 7 and 12.');
    header('Location: ../admin/dashboard.php');
    exit;
}

if (!in_array($sectionRaw, $SECTION_OPTIONS, true)) {
    setFlash('error', 'Please select a valid section.');
    header('Location: ../admin/dashboard.php');
    exit;
}

$gradeLevel   = (int) $gradeLevelRaw;
$academicYear = currentAcademicYear();

/* School name to store in enrolled_students */
$enrollSchoolName = trim((string) ($adminUser['school_name'] ?? ''));
if ($enrollSchoolName === '') {
    $enrollSchoolName = 'Eguia National High School';
}

/* Detect which student type column exists */
$studentTypeCol = columnExists($pdo, 'students', 'student_type')
    ? 'student_type'
    : (columnExists($pdo, 'students', 'student_status') ? 'student_status' : null);

if ($studentTypeCol === null) {
    setFlash('error', 'Student type column is missing in the students table.');
    header('Location: ../admin/dashboard.php');
    exit;
}

/* Detect whether enrollment_access column exists */
$hasEnrollmentAccessCol = columnExists($pdo, 'students', 'enrollment_access');

/* Detect whether class_adviser column exists (needed for INSERT/UPDATE) */
$hasClassAdviserCol = columnExists($pdo, 'enrolled_students', 'class_adviser');

try {
    $pdo->beginTransaction();

    /* Fetch + lock student row */
    $stmt = $pdo->prepare("
        SELECT account_number, {$studentTypeCol} AS student_type, enrollment_status
        FROM students
        WHERE account_number = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$accountNumber]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        $pdo->rollBack();
        setFlash('error', 'Student not found.');
        header('Location: ../admin/dashboard.php');
        exit;
    }

    if (!in_array($student['student_type'], ['N', 'T'], true)) {
        $pdo->rollBack();
        setFlash('error', 'Only New Students and Transferees can be enrolled from this action.');
        header('Location: ../admin/dashboard.php');
        exit;
    }

    if (strtolower((string) ($student['enrollment_status'] ?? '')) === 'enrolled') {
        $pdo->rollBack();
        setFlash('error', 'Student is already enrolled.');
        header('Location: ../admin/dashboard.php');
        exit;
    }

    /* Prevent duplicate rows for the same student + SY */
    $check = $pdo->prepare("
        SELECT 1 FROM enrolled_students
        WHERE account_number = ?
          AND academic_year = ?
        LIMIT 1
    ");
    $check->execute([$accountNumber, $academicYear]);
    $rowExists = (bool) $check->fetchColumn();

    /* ------------------------------------------------------------
       Resolve class adviser:
         1. assigned_teachers: grade_level + section + advisory = 1
            → grab that row's account_number
         2. teachers: look up first_name / middle_name / surname
         3. Build "First Middle Surname"
       ------------------------------------------------------------ */
    $classAdviser = null;

    if ($hasClassAdviserCol) {
        try {
            $atStmt = $pdo->prepare("
                SELECT account_number
                FROM assigned_teachers
                WHERE grade_level = ?
                  AND section = ?
                  AND advisory = 1
                LIMIT 1
            ");
            $atStmt->execute([(string) $gradeLevel, $sectionRaw]);
            $adviserAccountNumber = $atStmt->fetchColumn();

            if ($adviserAccountNumber) {
                $tStmt = $pdo->prepare("
                    SELECT first_name, middle_name, surname
                    FROM teachers
                    WHERE account_number = ?
                    LIMIT 1
                ");
                $tStmt->execute([$adviserAccountNumber]);
                $teacher = $tStmt->fetch(PDO::FETCH_ASSOC);

                if ($teacher) {
                    $classAdviser = trim(
                        ($teacher['first_name'] ?? '') . ' ' .
                        ($teacher['middle_name'] ?? '') . ' ' .
                        ($teacher['surname'] ?? '')
                    );
                    $classAdviser = preg_replace('/\s+/', ' ', $classAdviser) ?: null;
                } else {
                    error_log("enroll-students: teacher not found for account_number=" . var_export($adviserAccountNumber, true));
                }
            } else {
                error_log("enroll-students: no advisory teacher for grade={$gradeLevel} section={$sectionRaw}");
            }
        } catch (PDOException $e) {
            error_log("enroll-students: adviser lookup failed: " . $e->getMessage());
        }
    } else {
        error_log("enroll-students: enrolled_students.class_adviser column does not exist; skipping adviser write.");
    }

    /* ---------------- INSERT / UPDATE ---------------- */
    if ($rowExists) {
        if ($hasClassAdviserCol) {
            $upd = $pdo->prepare("
                UPDATE enrolled_students
                SET grade_level = ?, section = ?, status = 'Enrolled', school_name = ?, class_adviser = ?
                WHERE account_number = ? AND academic_year = ?
            ");
            $upd->execute([
                $gradeLevel,
                $sectionRaw,
                $enrollSchoolName,
                $classAdviser,
                $accountNumber,
                $academicYear,
            ]);
        } else {
            $upd = $pdo->prepare("
                UPDATE enrolled_students
                SET grade_level = ?, section = ?, status = 'Enrolled', school_name = ?
                WHERE account_number = ? AND academic_year = ?
            ");
            $upd->execute([
                $gradeLevel,
                $sectionRaw,
                $enrollSchoolName,
                $accountNumber,
                $academicYear,
            ]);
        }
    } else {
        if ($hasClassAdviserCol) {
            $ins = $pdo->prepare("
                INSERT INTO enrolled_students
                    (account_number, grade_level, section, academic_year, status, school_name, class_adviser)
                VALUES
                    (?, ?, ?, ?, 'Active', ?, ?)
            ");
            $ins->execute([
                $accountNumber,
                $gradeLevel,
                $sectionRaw,
                $academicYear,
                $enrollSchoolName,
                $classAdviser,
            ]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO enrolled_students
                    (account_number, grade_level, section, academic_year, status, school_name)
                VALUES
                    (?, ?, ?, ?, 'Active', ?)
            ");
            $ins->execute([
                $accountNumber,
                $gradeLevel,
                $sectionRaw,
                $academicYear,
                $enrollSchoolName,
            ]);
        }
    }

    /* Flip student record to 'enrolled' */
    if ($hasEnrollmentAccessCol) {
        $upd = $pdo->prepare("
            UPDATE students
            SET enrollment_status = 'Enrolled', student_type = 'O', 
                enrollment_access = 1
            WHERE account_number = ?
        ");
        $upd->execute([$accountNumber]);
    } else {
        $upd = $pdo->prepare("
           UPDATE students
            SET enrollment_status = 'Enrolled', student_type = 'O', 
                enrollment_access = 1
            WHERE account_number = ?
        ");
        $upd->execute([$accountNumber]);
    }

    $pdo->commit();

    setFlash(
        'success',
        "Student {$accountNumber} enrolled in Grade {$gradeLevel} - {$sectionRaw} for S.Y. {$academicYear}."
    );
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("enroll-students: enroll_student failed: " . $e->getMessage());
    setFlash('error', 'Could not enroll student. Please try again.');
}

header('Location: ../admin/dashboard.php');
exit;