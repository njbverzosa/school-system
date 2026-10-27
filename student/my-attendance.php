<?php
// student/my-attendance.php
// Shows: All attendance records for the logged-in student
require_once __DIR__ . '/../DB_Conn/config.php';

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
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ------------------------------------------------------------
   FETCH LOGGED-IN STUDENT
   ------------------------------------------------------------ */
$isLoggedIn = false;
$user = null;

if (!empty($_SESSION['account_number']) && is_string($_SESSION['account_number'])) {
    $accountNumber = trim($_SESSION['account_number']);

    if (strlen($accountNumber) > 5) {
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM students
                WHERE account_number = ?
                  AND user_type = 'student'
                LIMIT 1
            ");
            $stmt->execute([$accountNumber]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("student fetch failed: " . $e->getMessage());
            $user = null;
        }
    }

    if (!$user) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    } else {
        $isLoggedIn = true;
    }
}

$displayName = '';
if ($user) {
    $displayName = trim(
        ($user['first_name'] ?? '') . ' ' .
        ($user['middle_name'] ?? '') . ' ' .
        ($user['surname'] ?? '')
    );
}

/* ------------------------------------------------------------
   FETCH ALL ATTENDANCE RECORDS FOR THIS STUDENT
   Includes status + subject columns + time_start / time_end if present
   ------------------------------------------------------------ */
$attendanceRows = [];
$attendanceErr = null;
$totalRecords = 0;

if ($isLoggedIn && $user) {
    try {
        try {
            $stmt = $pdo->prepare("
                SELECT id, account_number, date_today, time_start, time_end,
                       grade_level, section, subject, status, adviser
                FROM attendance_list
                WHERE account_number = ?
                ORDER BY id DESC
            ");
            $stmt->execute([$user['account_number']]);
            $attendanceRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $inner) {
            $stmt = $pdo->prepare("
                SELECT id, account_number, date_today, grade_level, section, subject, status, adviser
                FROM attendance_list
                WHERE account_number = ?
                ORDER BY id DESC
            ");
            $stmt->execute([$user['account_number']]);
            $attendanceRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $totalRecords = count($attendanceRows);
    } catch (PDOException $e) {
        $attendanceErr = $e->getMessage();
        error_log("attendance fetch failed: " . $attendanceErr);
    }
}

/* ------------------------------------------------------------
   HELPERS
   ------------------------------------------------------------ */
function resolveAdviserName(PDO $pdo, string $adviserAccount, string $fallback = ''): string
{
    $adviserAccount = trim($adviserAccount);
    if ($adviserAccount === '') {
        return $fallback !== '' ? $fallback : '—';
    }

    try {
        $stmt = $pdo->prepare("
            SELECT first_name, middle_name, surname
            FROM teachers
            WHERE account_number = ?
            LIMIT 1
        ");
        $stmt->execute([$adviserAccount]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            return $fallback !== '' ? $fallback : $adviserAccount;
        }

        $parts = array_filter(
            [
                trim((string) ($row['first_name'] ?? '')),
                trim((string) ($row['middle_name'] ?? '')),
                trim((string) ($row['surname'] ?? '')),
            ],
            fn($p) => $p !== ''
        );

        $name = implode(' ', $parts);
        return $name !== '' ? $name : ($fallback !== '' ? $fallback : $adviserAccount);
    } catch (PDOException $e) {
        error_log("resolveAdviserName failed: " . $e->getMessage());
        return $fallback !== '' ? $fallback : $adviserAccount;
    }
}

$adviserNameCache = [];
function adviserNameCached(PDO $pdo, array &$cache, string $adviserAccount, string $fallback = ''): string
{
    $key = trim($adviserAccount) . '|' . $fallback;
    if (!isset($cache[$key])) {
        $cache[$key] = resolveAdviserName($pdo, $adviserAccount, $fallback);
    }
    return $cache[$key];
}

function statusClass(string $status): string
{
    $s = strtolower(trim($status));

    return match ($s) {
        'present', 'on time', 'ontime' => 'status-present',
        'absent' => 'status-absent',
        'late', 'tardy' => 'status-late',
        'excused', 'excuse' => 'status-excused',
        default => 'status-default',
    };
}

function formatTimePart(string $timeRaw): string
{
    $timeRaw = trim($timeRaw);
    if ($timeRaw === '') {
        return '';
    }

    if (preg_match('/^\d{1,2}:\d{2}\s*(AM|PM|am|pm)$/i', $timeRaw)) {
        $ts = strtotime($timeRaw);
        return $ts !== false ? date('g:iA', $ts) : $timeRaw;
    }

    if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $timeRaw)) {
        $ts = strtotime('1970-01-01 ' . $timeRaw);
        return $ts !== false ? date('g:iA', $ts) : $timeRaw;
    }

    if (preg_match('/^\d{1,2}$/', $timeRaw)) {
        $ts = strtotime('1970-01-01 ' . $timeRaw . ':00');
        return $ts !== false ? date('g:iA', $ts) : $timeRaw;
    }

    return $timeRaw;
}

function formatAttendanceDate(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '—';
    }

    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) {
            return date('M. j, Y', $ts);
        }
    }

    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('M. j, Y', $ts);
    }

    return $raw;
}

function formatAttendanceDisplay(array $row): string
{
    $dateRaw = trim((string) ($row['date_today'] ?? ''));
    $timeStartRaw = trim((string) ($row['time_start'] ?? ''));
    $timeEndRaw = trim((string) ($row['time_end'] ?? ''));

    if ($timeStartRaw === '' && $timeEndRaw === '' && $dateRaw !== '') {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2}(?::\d{2})?)\s*[-–]\s*(\d{1,2}:\d{2}(?::\d{2})?)$/i', $dateRaw, $m)) {
            $datePart = $m[1];
            $timeStartRaw = $m[2];
            $timeEndRaw = $m[3];
            $dateRaw = $datePart;
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2}(?::\d{2})?)$/i', $dateRaw, $m)) {
            $datePart = $m[1];
            $timeStartRaw = $m[2];
            $dateRaw = $datePart;
        }
    }

    $dateFormatted = formatAttendanceDate($dateRaw);
    if ($dateFormatted === '—') {
        return '—';
    }

    $startFormatted = formatTimePart($timeStartRaw);
    $endFormatted = formatTimePart($timeEndRaw);

    if ($startFormatted !== '' && $endFormatted !== '') {
        return $dateFormatted . ' - ' . $startFormatted . ' - ' . $endFormatted;
    }
    if ($startFormatted !== '') {
        return $dateFormatted . ' - ' . $startFormatted;
    }
    if ($endFormatted !== '') {
        return $dateFormatted . ' - ' . $endFormatted;
    }

    return $dateFormatted;
}
?>
<!DOCTYPE html>
<html lang="fil">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>" />
    <title>My Attendance — Eguia National High School</title>
    <meta name="description" content="Opisyal na portal ng Eguia National High School sa Dasol, Pangasinan." />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        :root {
            --primary: #1e40af;
            --primary-2: #3b82f6;
            --primary-dark: #1d4ed8;
            --bg: #f8fafc;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --radius: 8px;
            --shadow-md: 0 14px 34px -14px rgba(15, 23, 42, .22);
            --green: #047857;
            --red: #b91c1c;
            --amber: #b45309;
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            overflow-y: scroll;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        img,
        svg {
            display: block;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        h1,
        h2,
        h3,
        h4 {
            font-family: 'Poppins', sans-serif;
            line-height: 1.18;
            letter-spacing: -.02em;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* NAV */
        .nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255, 255, 255, .96);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--line);
        }

        .nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 14px 0;
            min-height: 108px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 20px;
            min-width: 0;
            text-decoration: none;
            flex-shrink: 1;
        }

        .logo-mark {
            width: 80px;
            height: 80px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
        }

        .logo-mark img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .logo-divider {
            width: 1px;
            height: 54px;
            background: var(--line);
            flex-shrink: 0;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
            justify-content: center;
            line-height: 1.1;
            min-width: 0;
            gap: 3px;
        }

        .logo-text b {
            font-family: 'Poppins', sans-serif;
            font-size: 26px;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -.02em;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 480px;
        }

        .logo-text .school-name {
            font-size: 13.5px;
            font-weight: 500;
            color: var(--muted);
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 480px;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }

        .page-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--primary-dark);
            padding: 8px 16px;
            white-space: nowrap;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            position: relative;
            flex-shrink: 0;
        }

        .nav-toggle {
            display: grid;
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            background: #f1f5f9;
            border: 1px solid var(--line);
            color: var(--text);
            cursor: pointer;
            place-items: center;
            font-size: 20px;
            transition: background .18s, color .18s, transform .18s;
        }

        .nav-toggle:hover {
            background: #eef2ff;
            color: var(--primary-dark);
        }

        .nav-toggle:active {
            transform: scale(.96);
        }

        .nav-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            left: auto;
            width: 280px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            padding: 8px;
            z-index: 200;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
        }

        .nav-dropdown.open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .nav-dropdown-head {
            padding: 10px 14px 8px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #94a3b8;
            font-weight: 700;
            border-bottom: 1px solid var(--line);
            margin-bottom: 6px;
        }

        .nav-dropdown a,
        .nav-dropdown button {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: var(--radius);
            color: var(--text);
            font-size: 15px;
            font-weight: 500;
            transition: background .15s, color .15s;
            cursor: pointer;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
            font-family: inherit;
        }

        .nav-dropdown a:hover,
        .nav-dropdown button:hover {
            background: #eef2ff;
            color: var(--primary-dark);
        }

        .nav-dropdown a i,
        .nav-dropdown button i {
            font-size: 16px;
            width: 18px;
            text-align: center;
            flex-shrink: 0;
            color: var(--primary);
        }

        /* PANEL */
        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            padding: 28px;
            margin: 30px 0 60px;
        }

        /* PAGE HEAD */
        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px dashed var(--line);
            flex-wrap: wrap;
        }

        .page-head h2 {
            font-size: 20px;
            font-weight: 800;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-head h2 i {
            color: var(--primary);
            font-size: 18px;
        }

        .page-head .sub {
            font-size: 13px;
            color: var(--muted);
            display: block;
            margin-top: 2px;
        }

        .count-pill {
            font-size: 12px;
            font-weight: 600;
            padding: 5px 14px;
            border-radius: 999px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            color: var(--primary-dark);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .count-pill i {
            font-size: 11px;
        }

        /* SUMMARY STRIP */
        .attendance-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .summary-card {
            background: linear-gradient(135deg, #f8fafc, #eef2ff);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .summary-card .s-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #94a3b8;
        }

        .summary-card .s-value {
            font-family: 'Poppins', sans-serif;
            font-size: 22px;
            font-weight: 800;
            color: var(--primary-dark);
            line-height: 1.1;
        }

        /* TABLE */
        .attendance-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            -webkit-overflow-scrolling: touch;
        }

        table.attendance {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        table.attendance thead {
            background: #f1f5f9;
        }

        table.attendance th,
        table.attendance td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        table.attendance th {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #64748b;
        }

        table.attendance tbody tr:last-child td {
            border-bottom: none;
        }

        table.attendance tbody tr:hover {
            background: #f8fafc;
        }

        .cell-date {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 14px;
        }

        .cell-acc {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 13px;
        }

        .cell-grade {
            font-weight: 600;
            color: var(--text);
        }

        .cell-grade.empty {
            color: #cbd5e1;
            font-style: italic;
            font-weight: 400;
        }

        .cell-subject {
            font-weight: 500;
            color: var(--text);
        }

        .cell-subject.empty {
            color: #cbd5e1;
            font-style: italic;
            font-weight: 400;
        }

        .cell-section {
            font-weight: 500;
            color: var(--text);
        }

        .cell-adviser {
            font-weight: 500;
            color: var(--text);
        }

        .cell-adviser.empty,
        .cell-section.empty {
            color: #cbd5e1;
            font-style: italic;
            font-weight: 400;
        }

        /* STATUS PILLS — text color only */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 700;
            padding: 0;
            border-radius: 0;
            border: none;
            background: transparent;
            color: var(--muted);
            text-transform: capitalize;
            white-space: nowrap;
        }

        .status-pill.status-present {
            color: var(--green);
        }

        .status-pill.status-absent {
            color: var(--red);
        }

        .status-pill.status-late {
            color: var(--amber);
        }

        .status-pill.status-excused {
            color: var(--primary-dark);
        }

        .status-pill.status-default {
            color: var(--muted);
        }

        /* EMPTY */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
            font-size: 14px;
            border: 1px dashed var(--line);
            border-radius: var(--radius);
        }

        .empty-state i {
            font-size: 40px;
            color: #cbd5e1;
            margin-bottom: 12px;
            display: block;
        }

        .empty-state .debug {
            display: block;
            margin-top: 10px;
            font-size: 11.5px;
            color: var(--red);
            font-family: monospace;
            word-break: break-all;
        }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            .nav-inner {
                padding: 12px 0;
                gap: 14px;
                min-height: 92px;
            }

            .nav-toggle {
                width: 46px;
                height: 46px;
                font-size: 19px;
            }

            .logo {
                gap: 16px;
            }

            .logo-mark {
                width: 68px;
                height: 68px;
            }

            .logo-divider {
                height: 46px;
            }

            .logo-text b {
                font-size: 23px;
                max-width: 380px;
            }

            .logo-text .school-name {
                font-size: 12.5px;
                max-width: 380px;
            }

            .page-label {
                font-size: 11.5px;
                padding: 7px 14px;
            }
        }

        @media (max-width: 820px) {
            .nav-inner {
                padding: 10px 0;
                gap: 10px;
                min-height: 76px;
            }

            .logo {
                gap: 12px;
                flex: 1;
                min-width: 0;
            }

            .logo-mark {
                width: 56px;
                height: 56px;
            }

            .logo-divider {
                height: 40px;
            }

            .logo-text b {
                font-size: 19px;
                max-width: 240px;
            }

            .logo-text .school-name {
                font-size: 11px;
                max-width: 240px;
            }

            .nav-toggle {
                width: 44px;
                height: 44px;
                font-size: 18px;
            }

            .page-label {
                font-size: 10.5px;
                padding: 6px 12px;
                gap: 6px;
            }

            .attendance-summary {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 640px) {
            .container {
                padding: 0 16px;
            }

            .nav-inner {
                padding: 10px 0;
                gap: 8px;
                min-height: 66px;
            }

            .logo {
                gap: 10px;
            }

            .logo-mark {
                width: 46px;
                height: 46px;
            }

            .logo-divider {
                display: none;
            }

            .logo-text b {
                font-size: 16px;
                max-width: 160px;
            }

            .logo-text .school-name {
                font-size: 10px;
                max-width: 160px;
            }

            .nav-toggle {
                width: 42px;
                height: 42px;
                font-size: 17px;
            }

            .page-label {
                display: none;
            }

            .nav-dropdown {
                width: 250px;
            }

            .panel {
                padding: 20px 16px;
                margin: 20px 0 40px;
            }

            .page-head h2 {
                font-size: 17px;
            }

            .attendance-summary {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .summary-card {
                padding: 14px;
            }

            .summary-card .s-value {
                font-size: 18px;
            }

            table.attendance th,
            table.attendance td {
                padding: 10px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 400px) {
            .logo-mark {
                width: 40px;
                height: 40px;
            }

            .logo-text b {
                font-size: 14px;
                max-width: 130px;
            }

            .logo-text .school-name {
                font-size: 9px;
                max-width: 130px;
            }
        }
    </style>
</head>

<body>
    <header class="nav" id="nav">
        <div class="container nav-inner">
            <a href="#" class="logo">
                <div class="logo-mark">
                    <img src="https://campuslink.villaruz-print-shop-and-general-merchandise.shop/Images/logo.png"
                        alt="Eguia National High School logo" onerror="this.style.display='none'">
                </div>
                <div class="logo-divider" aria-hidden="true"></div>
                <div class="logo-text">
                    <b>CampusLink</b>
                    <span class="school-name">Eguia National High School</span>
                </div>
            </a>

            <div class="nav-right">
                <span class="page-label">Student Portal</span>

                <div class="nav-actions">
                    <button class="nav-toggle" id="navToggle" aria-label="Buksan ang menu" aria-expanded="false">
                        <i class="fas fa-bars"></i>
                    </button>

                    <div class="nav-dropdown" id="navDropdown" role="menu">
                        <div class="nav-dropdown-head">MENU</div>

                        <a href="academic-information.php" role="menuitem">
                            <i class="fas fa-calendar-days"></i> Subject Schedule
                        </a>

                        <a href="my-attendance.php" role="menuitem">
                            <i class="fas fa-clipboard-user"></i> My Attendance
                        </a>

                        <a href="student-grades.php" role="menuitem">
                            <i class="fas fa-chart-line"></i> Grades &amp; Transcripts
                        </a>

                        <a href="my-profile.php" role="menuitem">
                            <i class="fas fa-folder-open"></i> Master File
                        </a>

                        <div style="border-top: 1px solid var(--line); margin-top: 6px; padding-top: 6px;">
                            <a href="logout.php" role="menuitem" style="color: #b91c1c;">
                                <i class="fas fa-right-from-bracket" style="color: #b91c1c;"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container">
        <?php if ($isLoggedIn && $user): ?>
            <section class="panel">

                <!-- PAGE HEAD -->
                <div class="page-head">
                    <div>
                        <h2><i class="fas fa-clipboard-user"></i> My Attendance</h2>
                        <span class="sub">
                            <?php echo htmlspecialchars($displayName !== '' ? $displayName : 'Student'); ?>
                            &middot; <?php echo htmlspecialchars($user['account_number'] ?? '—'); ?>
                        </span>
                    </div>

                    <?php if ($totalRecords > 0): ?>
                        <span class="count-pill">
                            <i class="fas fa-list-check"></i>
                            <?php echo $totalRecords; ?>
                            record<?php echo $totalRecords === 1 ? '' : 's'; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($attendanceErr !== null): ?>
                    <div class="empty-state">
                        <i class="fas fa-triangle-exclamation"></i>
                        <p>Unable to load your attendance records right now. Please try again later.</p>
                        <span class="debug"><?php echo htmlspecialchars($attendanceErr); ?></span>
                    </div>

                <?php elseif (empty($attendanceRows)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No attendance records found yet.</p>
                    </div>

                <?php else: ?>
                    <!-- SUMMARY -->


                    <!-- TABLE -->
                    <div class="attendance-wrap">
                        <table class="attendance">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Account #</th>
                                    <th>Date &amp; Time</th>
                                    <th>Grade Level</th>
                                    <th>Section</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th>Adviser</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendanceRows as $index => $row): ?>
                                    <?php
                                    $rawAdviser = trim((string) ($row['adviser'] ?? ''));
                                    $adviserName = adviserNameCached($pdo, $adviserNameCache, $rawAdviser);
                                    $adviserIsEmpty = ($adviserName === '' || $adviserName === '—');

                                    $gradeVal = trim((string) ($row['grade_level'] ?? ''));
                                    $sectionVal = trim((string) ($row['section'] ?? ''));
                                    $subjectVal = trim((string) ($row['subject'] ?? ''));
                                    $statusVal = trim((string) ($row['status'] ?? ''));
                                    $accVal = trim((string) ($row['account_number'] ?? ''));
                                    $idVal = (int) ($row['id'] ?? 0);

                                    $statusKey = statusClass($statusVal);
                                    $displayDateTime = formatAttendanceDisplay($row);
                                    ?>
                                    <tr>
                                        <td><?php echo $idVal; ?></td>
                                        <td class="cell-acc">
                                            <?php echo htmlspecialchars($accVal !== '' ? $accVal : '—'); ?>
                                        </td>
                                        <td class="cell-date">
                                            <?php echo htmlspecialchars($displayDateTime); ?>
                                        </td>
                                        <td>
                                            <span class="cell-grade <?php echo $gradeVal === '' ? 'empty' : ''; ?>">
                                                <?php echo htmlspecialchars($gradeVal !== '' ? $gradeVal : '—'); ?>
                                            </span>
                                        </td>
                                        <td class="cell-section <?php echo $sectionVal === '' ? 'empty' : ''; ?>">
                                            <?php echo htmlspecialchars($sectionVal !== '' ? $sectionVal : '—'); ?>
                                        </td>
                                        <td class="cell-subject <?php echo $subjectVal === '' ? 'empty' : ''; ?>">
                                            <?php echo htmlspecialchars($subjectVal !== '' ? $subjectVal : '—'); ?>
                                        </td>
                                        <td>
                                            <?php if ($statusVal !== ''): ?>
                                                <span class="status-pill <?php echo $statusKey; ?>">
                                                    <?php echo htmlspecialchars($statusVal); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="status-pill status-default">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="cell-adviser <?php echo $adviserIsEmpty ? 'empty' : ''; ?>">
                                            <?php echo htmlspecialchars($adviserName !== '' ? $adviserName : '—'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </section>
        <?php endif; ?>
    </main>

    <script>
        const navToggle = document.getElementById('navToggle');
        const navDropdown = document.getElementById('navDropdown');

        navToggle?.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = navDropdown.classList.toggle('open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', (e) => {
            if (navDropdown && !navDropdown.contains(e.target) && navToggle && e.target !== navToggle) {
                navDropdown.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });
    </script>
</body>

</html>