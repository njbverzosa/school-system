<?php
// API_STUDENT/student-enrollment.php — Enrollment backend.
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
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ------------------------------------------------------------
   SECTION OPTIONS — per grade (server-side whitelist)
   ------------------------------------------------------------ */
$SECTION_OPTIONS_BY_GRADE = [
    '7'  => ['Ilang-Ilang', 'Dahlia', 'Rosal', 'Sampaguita'],
    '8'  => ['Acacia', 'Yakal', 'Molave', 'Mahogany'],
    '9'  => ['Mabini', 'Rizal', 'Jacinto', 'Bonifacio'],
    '10' => ['Emerald', 'Diamond', 'Jade'],
    '11' => ['Home Economics - Pasteur', 'Einstein', 'Socrates', 'Franklin'],
    '12' => ['Automotive Servicing', 'TVL - HE', 'STEM', 'Humanities & Social Science'],
];

/* Flat list kept for quick membership checks */
$SECTION_OPTIONS = array_merge(...array_values($SECTION_OPTIONS_BY_GRADE));

function currentAcademicYear(): string
{
    $y = (int) date('Y');
    return ((int) date('n') < 6) ? (($y - 1) . '-' . $y) : ($y . '-' . ($y + 1));
}

function nextGradeLevel(string $current): string
{
    $current = trim($current);
    if ($current === '') return '7';
    if (preg_match('/(?:grade\s*|g)?\s*(\d{1,2})/i', $current, $m)) {
        $n = (int) $m[1];
        if ($n >= 1 && $n < 12) $n++;
        elseif ($n >= 12) $n = 12;
        return (string) $n;
    }
    return $current;
}

function flash(string $key, string $msg): void
{
    $_SESSION['flash'][$key] = $msg;
}

function redirectHome(): void
{
    header('Location: ../student/academic-information.php');
    exit;
}

/* ------------------------------------------------------------
   0. Method guard
   ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('enroll_error', 'Invalid request method.');
    redirectHome();
}

/* 0b. CSRF */
$clientToken = $_POST['csrf_token'] ?? '';
if (empty($clientToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $clientToken)) {
    flash('enroll_error', 'Invalid security token. Refresh and try again.');
    redirectHome();
}

/* 0c. Section presence */
$postedSection = trim((string) ($_POST['section'] ?? ''));
if ($postedSection === '') {
    flash('enroll_error', 'Please select a section.');
    redirectHome();
}

/* ------------------------------------------------------------
   1. Auth
   ------------------------------------------------------------ */
$accountNumber = $_SESSION['account_number'] ?? null;
if (!is_string($accountNumber) || strlen($accountNumber) < 5) {
    flash('enroll_error', 'Invalid session. Please log in again.');
    redirectHome();
}

/* ------------------------------------------------------------
   2. Load student
   ------------------------------------------------------------ */
$student = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE account_number = ? AND user_type = 'student' LIMIT 1");
    $stmt->execute([$accountNumber]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log("enrollment: student fetch failed: " . $e->getMessage());
}

if (!$student) {
    flash('enroll_error', 'Student account not found.');
    redirectHome();
}

/* ------------------------------------------------------------
   3. Status check
   enrollment_access = 1 means the toggle is OPEN.
   enrollment_access = 0 means CLOSED → reject.
   ------------------------------------------------------------ */
$enrollmentAccess = (int) ($student['enrollment_access'] ?? 0);
if ($enrollmentAccess !== 1) {
    flash('enroll_error', 'Enrollment is currently closed. Please wait for the school to open it.');
    redirectHome();
}

/* Already enrolled or already pending? */
$currentEnrollmentStatus = strtolower(trim((string) ($student['enrollment_status'] ?? 'not enrolled')));
if (in_array($currentEnrollmentStatus, ['enrolled', 'active'], true)) {
    flash('enroll_error', 'You are already enrolled for this academic year.');
    redirectHome();
}
// CHANGED: block re-submission when a pending request already exists
if ($currentEnrollmentStatus === 'pending') {
    flash('enroll_error', 'You already submitted an enrollment request. Please wait for approval.');
    redirectHome();
}

/* ------------------------------------------------------------
   4. Duplicate check (same student + same academic year)
   ------------------------------------------------------------ */
$currentYear = currentAcademicYear();
try {
    $stmt = $pdo->prepare("SELECT status FROM enrolled_students WHERE account_number = ? AND academic_year = ? LIMIT 1");
    $stmt->execute([$accountNumber, $currentYear]);
    $existingStatus = $stmt->fetchColumn();
    if ($existingStatus !== false) {
        flash('enroll_error', 'You already have an enrollment record (' . $existingStatus . ') for ' . $currentYear . '.');
        redirectHome();
    }
} catch (PDOException $e) {
    error_log("enrollment: duplicate check failed: " . $e->getMessage());
    flash('enroll_error', 'Unable to check enrollment status. Please try again later.');
    redirectHome();
}

/* ------------------------------------------------------------
   5. Determine grade level
   - Old Student (O): promote from last enrolled_students row
   - New / Transferee: use students.grade_level
   ------------------------------------------------------------ */
$studentType = strtoupper(trim((string) ($student['student_type'] ?? '')));
$isOldStudent = ($studentType === 'O');

$gradeLevel = trim((string) ($student['grade_level'] ?? ''));

if ($isOldStudent) {
    try {
        $stmt = $pdo->prepare("
            SELECT grade_level
            FROM enrolled_students
            WHERE account_number = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$accountNumber]);
        $lastGrade = $stmt->fetchColumn();

        if ($lastGrade !== false && trim((string) $lastGrade) !== '') {
            $gradeLevel = nextGradeLevel((string) $lastGrade);
        }
    } catch (PDOException $e) {
        error_log("enrollment: last grade fetch failed: " . $e->getMessage());
    }
}

if ($gradeLevel === '') {
    $gradeLevel = '7';
}

/* ------------------------------------------------------------
   5b. Validate section against the student's grade
   ------------------------------------------------------------ */
$allowedSections = $SECTION_OPTIONS_BY_GRADE[$gradeLevel] ?? [];

if (!in_array($postedSection, $allowedSections, true)) {
    flash(
        'enroll_error',
        'The selected section "' . $postedSection . '" does not belong to Grade ' . $gradeLevel . '.'
    );
    redirectHome();
}

/* ------------------------------------------------------------
   6. Submit enrollment request (Pending)
   - Insert request row with status 'Pending'
   - Set students.enrollment_status = 'Pending'
   - Keep students.enrollment_access = 1 (still open)
   ------------------------------------------------------------ */
$schoolName = trim((string) ($student['school_name'] ?? ''));

try {
    $pdo->beginTransaction();

    // CHANGED: insert with status 'Pending' instead of 'Active'
    $stmt = $pdo->prepare("
        INSERT INTO enrolled_students
            (account_number, academic_year, grade_level, section, school_name, status)
        VALUES
            (:account_number, :academic_year, :grade_level, :section, :school_name, 'Pending')
    ");
    $stmt->execute([
        ':account_number' => $accountNumber,
        ':academic_year'  => $currentYear,
        ':grade_level'    => $gradeLevel,
        ':section'        => $postedSection,
        ':school_name'    => $schoolName,
    ]);

    // CHANGED: set status to 'Pending' and leave enrollment_access at 1
    $stmt = $pdo->prepare("
        UPDATE students
        SET enrollment_status = 'Pending'
        WHERE account_number = ?
    ");
    $stmt->execute([$accountNumber]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($e->getCode() === '23000') {
        flash('enroll_error', 'You already have an enrollment request for the ' . $currentYear . ' academic year.');
    } else {
        error_log("enrollment insert failed: " . $e->getMessage());
        flash('enroll_error', 'Enrollment submission failed. Please try again later.');
    }
    redirectHome();
}

/* ------------------------------------------------------------
   7. Cache subjects (optional)
   ------------------------------------------------------------ */
try {
    $stmt = $pdo->prepare("
        SELECT subject, adviser
        FROM assigned_teachers
        WHERE CAST(grade_level AS CHAR) = ?
          AND section = ?
        ORDER BY subject ASC
    ");
    $stmt->execute([$gradeLevel, $postedSection]);
    $_SESSION['flash']['enrolled_subjects'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("enrollment: assigned_teachers fetch failed: " . $e->getMessage());
    $_SESSION['flash']['enrolled_subjects'] = [];
}

/* ------------------------------------------------------------
   8. Success
   ------------------------------------------------------------ */
$_SESSION['flash']['just_enrolled'] = true;
// CHANGED: success message reflects the Pending state
flash(
    'enroll_success',
    'Your enrollment request for ' . $currentYear
    . ' (Grade ' . $gradeLevel . ' - ' . $postedSection . ') has been submitted. '
    . 'Status: Pending. Please wait for the school to approve it.'
);
redirectHome();