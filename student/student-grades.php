<?php
// student/student-grades.php
// Shows: Grades for the logged-in student
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

/* ------------------------------------------------------------
   DISPLAY NAME
   ------------------------------------------------------------ */
$displayName = '';
if ($user) {
    $displayName = trim(
        ($user['first_name'] ?? '') . ' ' .
        ($user['middle_name'] ?? '') . ' ' .
        ($user['surname'] ?? '')
    );
}

/* ------------------------------------------------------------
   HELPER: resolve teacher surname from account_number
   ------------------------------------------------------------ */
function resolveAdviserSurname(PDO $pdo, string $adviserAccount, array &$cache): string
{
    $adviserAccount = trim($adviserAccount);
    if ($adviserAccount === '') {
        return '—';
    }

    if (array_key_exists($adviserAccount, $cache)) {
        return $cache[$adviserAccount];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT surname
            FROM teachers
            WHERE account_number = ?
            LIMIT 1
        ");
        $stmt->execute([$adviserAccount]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $surname = $row ? trim((string) ($row['surname'] ?? '')) : '';
        $cache[$adviserAccount] = $surname !== '' ? $surname : $adviserAccount;
    } catch (PDOException $e) {
        error_log("resolveAdviserSurname failed: " . $e->getMessage());
        $cache[$adviserAccount] = $adviserAccount;
    }

    return $cache[$adviserAccount];
}

/* ------------------------------------------------------------
   FETCH ALL GRADES FOR THIS STUDENT
   ------------------------------------------------------------ */
$studentGrades = [];
$gradesFetchErr = null;

if ($isLoggedIn && $user) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                id,
                account_number,
                grade_level,
                section,
                subject,
                quarter,
                grade,
                adviser,
                academic_year
            FROM student_grades
            WHERE account_number = ?
            ORDER BY academic_year DESC, quarter ASC, subject ASC
        ");
        $stmt->execute([$user['account_number']]);
        $studentGrades = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        $gradesFetchErr = $e->getMessage();
        error_log("grades fetch failed: " . $gradesFetchErr);
        $studentGrades = [];
    }
}

/* Group grades by academic year + quarter for display */
$groupedGrades = [];
foreach ($studentGrades as $row) {
    $ay = trim((string) ($row['academic_year'] ?? '')) ?: '—';
    $q  = trim((string) ($row['quarter'] ?? '')) ?: '—';
    $key = $ay . '|' . $q;
    if (!isset($groupedGrades[$key])) {
        $groupedGrades[$key] = [
            'academic_year' => $ay,
            'quarter'       => $q,
            'rows'          => [],
        ];
    }
    $groupedGrades[$key]['rows'][] = $row;
}

$totalGradeRows = count($studentGrades);
$adviserSurnameCache = [];
?>
<!DOCTYPE html>
<html lang="fil">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>" />
    <title>Grades &amp; Transcripts — Eguia National High School</title>
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
            --green-bg: #ecfdf5;
            --green-line: #a7f3d0;
            --red: #b91c1c;
            --red-bg: #fef2f2;
            --red-line: #fecaca;
            --amber: #b45309;
            --amber-bg: #fffbeb;
            --amber-line: #fde68a;
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

        /* ── PAGE BACKGROUND ── */
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: #1f2327;
            background-image:
                linear-gradient(rgba(15, 23, 42, .18), rgba(15, 23, 42, .18)),
                url('../Images/Colorful_Back_to_School_Stationery_Border.png');
            background-size: cover;
            background-position: center top;
            background-repeat: no-repeat;
            background-attachment: fixed;
            color: var(--text);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
            min-height: 100vh;
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

        /* ── NAV ── */
        .nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #08285f;
            border-bottom: 0;
            overflow: visible;
            isolation: isolate;
        }

        .nav::before,
        .nav::after {
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            width: 250px;
            z-index: -1;
            pointer-events: none;
        }

        .nav::before {
            left: -90px;
            background:
                linear-gradient(55deg,
                    transparent 0 24%,
                    #9b1536 24% 34%,
                    #f8c324 34% 42%,
                    #2058c8 42% 56%,
                    transparent 56% 100%);
        }

        .nav::after {
            right: -90px;
            transform: scaleX(-1);
            background:
                linear-gradient(55deg,
                    transparent 0 24%,
                    #9b1536 24% 34%,
                    #f8c324 34% 42%,
                    #2058c8 42% 56%,
                    transparent 56% 100%);
        }

        .nav-inner {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 10px 0;
            min-height: 120px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 22px;
            min-width: 0;
            text-decoration: none;
            flex-shrink: 1;
        }

        .logo-mark {
            width: 191px;
            height: 107px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            overflow: hidden;
            background: #08285f;
            border-radius: 2px;
        }

        .logo-mark img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .logo-divider {
            display: none;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
            justify-content: center;
            line-height: 1.1;
            min-width: 0;
            gap: 0;
        }

        .logo-text b {
            font-family: 'Poppins', sans-serif;
            font-size: 30px;
            font-weight: 800;
            color: #fff;
            letter-spacing: .01em;
            line-height: 1.1;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .logo-text .school-name {
            display: none;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-shrink: 0;
        }

        .page-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #fff;
            padding: 8px 10px;
            white-space: nowrap;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            position: relative;
            flex-shrink: 0;
            z-index: 201;
        }

        .nav-toggle {
            display: grid;
            width: 60px;
            height: 60px;
            border-radius: 15px;
            background: #f8fafc;
            border: 0;
            color: #0f172a;
            cursor: pointer;
            place-items: center;
            font-size: 24px;
            transition: background .18s, color .18s, transform .18s;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .12);
        }

        .nav-toggle:hover {
            background: #fff;
            color: #08285f;
        }

        .nav-toggle:active {
            transform: scale(.96);
        }

        .nav-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            left: auto;
            width: 280px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
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
            border-radius: 8px;
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

        /* ── PANEL ── */
        .panel {
            background: rgba(8, 40, 95, .72);
            border: 1px solid rgba(255, 255, 255, .3);
            border-radius: var(--radius);
            box-shadow: 0 14px 34px -14px rgba(0, 0, 0, .35);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
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
            border-bottom: 1px dashed rgba(255, 255, 255, .3);
            flex-wrap: wrap;
        }

        .page-head h2 {
            font-size: 20px;
            font-weight: 800;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-head h2 i {
            color: #fbbf24;
            font-size: 18px;
        }

        .page-head .sub {
            font-size: 13px;
            color: rgba(255, 255, 255, .75);
            display: block;
            margin-top: 2px;
        }

        /* QUARTER GROUP */
        .quarter-group {
            margin-bottom: 28px;
        }

        .quarter-group:last-child {
            margin-bottom: 0;
        }

        .quarter-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .quarter-head h3 {
            font-size: 14px;
            font-weight: 800;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quarter-head h3 i {
            color: #fbbf24;
            font-size: 13px;
        }

        .quarter-head .ay-badge {
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
            background: rgba(8, 40, 95, .78);
            border: 1px solid rgba(255, 255, 255, .3);
            color: #fff;
        }

        /* ── TABLE — TRANSPARENT BACKGROUND, WHITE TEXT ── */
        .grades-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            -webkit-overflow-scrolling: touch;
            background: transparent;
        }

        table.grades {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background: transparent;
        }

        table.grades thead {
            background: rgba(255, 255, 255, 0.1);
        }

        table.grades th,
        table.grades td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #ffffff !important;
        }

        table.grades th {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: rgba(255, 255, 255, 0.7) !important;
        }

        table.grades tbody tr:last-child td {
            border-bottom: none;
        }



        .cell-grade {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            color: #ffffff !important;
            font-size: 15px;
        }

        .cell-id {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            color: #ffffff !important;
            font-size: 13px;
        }

        /* EMPTY */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, .85);
            font-size: 14px;
            border: 1px dashed rgba(255, 255, 255, .3);
            border-radius: var(--radius);
        }

        .empty-state i {
            font-size: 40px;
            color: rgba(255, 255, 255, .4);
            margin-bottom: 12px;
            display: block;
        }

        .empty-state .debug {
            display: block;
            margin-top: 10px;
            font-size: 11.5px;
            color: #fca5a5;
            font-family: monospace;
            word-break: break-all;
        }

        /* GLOBAL WHITE TEXT */
        body,
        body :where(h1, h2, h3, h4, h5, h6, p, span, div, a, button, label, strong, b, small, th, td, li, i, em, code) {
            color: #ffffff !important;
        }

        /* Nav dropdown readable */
        .nav-dropdown {
            background: #08285f;
            border-color: rgba(255, 255, 255, .22);
        }

        .nav-dropdown-head {
            color: #ffffff !important;
            border-bottom-color: rgba(255, 255, 255, .22);
        }

        .nav-dropdown a:hover,
        .nav-dropdown button:hover {
            background: rgba(255, 255, 255, .14);
            color: #ffffff !important;
        }

        .nav-dropdown a i,
        .nav-dropdown button i {
            color: #ffffff !important;
        }

        .nav-toggle {
            color: #ffffff !important;
            background: #123b78;
        }

        .nav-toggle:hover {
            background: #1d4e89;
            color: #ffffff !important;
        }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            .nav-inner {
                padding: 10px 0;
                gap: 14px;
                min-height: 100px;
            }

            .logo {
                gap: 16px;
            }

            .logo-mark {
                width: 150px;
                height: 84px;
            }

            .logo-text b {
                font-size: 25px;
            }

            .nav-toggle {
                width: 54px;
                height: 54px;
                font-size: 22px;
            }
        }

        @media (max-width: 820px) {
            .nav-inner {
                padding: 8px 0;
                gap: 10px;
                min-height: 82px;
            }

            .logo {
                gap: 12px;
                flex: 1;
                min-width: 0;
            }

            .logo-mark {
                width: 125px;
                height: 70px;
            }

            .logo-text b {
                font-size: 21px;
            }

            .nav-toggle {
                width: 48px;
                height: 48px;
                font-size: 20px;
                border-radius: 12px;
            }

            .page-label {
                font-size: 11px;
                padding: 6px 8px;
            }
        }

        @media (max-width: 640px) {
            .container {
                padding: 0 16px;
            }

            .nav-inner {
                padding: 7px 0;
                gap: 8px;
                min-height: 68px;
            }

            .logo {
                gap: 8px;
            }

            .logo-mark {
                width: 100px;
                height: 56px;
            }

            .logo-text b {
                font-size: 16px;
                max-width: 145px;
            }

            .nav-toggle {
                width: 42px;
                height: 42px;
                font-size: 17px;
                border-radius: 10px;
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

            table.grades th,
            table.grades td {
                padding: 10px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 400px) {
            .nav::before,
            .nav::after {
                width: 150px;
            }

            .logo-mark {
                width: 88px;
                height: 49px;
            }

            .logo-text b {
                font-size: 14px;
                max-width: 125px;
            }

            .nav-toggle {
                width: 40px;
                height: 40px;
            }
        }

        /* ── DEVTOOLS ACTIVE ── */
        body.devtools-active {
            overflow: hidden !important;
            background: #000 !important;
        }

        body.devtools-active>* {
            visibility: hidden !important;
        }

        body.devtools-active::before {
            content: "" !important;
            position: fixed !important;
            inset: 0 !important;
            top: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            z-index: 2147483647 !important;
            background-color: #000 !important;
            background-image: url('../Images/inspect.png') !important;
            background-repeat: no-repeat !important;
            background-position: 50% 50% !important;
            background-size: contain !important;
            pointer-events: none !important;
            visibility: visible !important;
        }
    </style>
</head>

<body>

    <header class="nav" id="nav">
        <div class="container nav-inner">
            <a href="#" class="logo">
                <div class="logo-mark">
                    <img src="../Images/campuslink-logo-navy.png"
                        alt="Eguia National High School logo" onerror="this.style.display='none'">
                </div>
                <div class="logo-divider" aria-hidden="true"></div>
                <div class="logo-text">
                    <b>CampusLink</b>
                    <span class="school-name">Eguia National High School</span>
                </div>
            </a>

            <div class="nav-right">
                <span class="page-label">MY GRADES</span>

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
                        <h2><i class="fas fa-chart-line"></i> My Grades</h2>
                        <span class="sub">
                            <?php echo htmlspecialchars($displayName !== '' ? $displayName : 'Student'); ?>
                            &middot; <?php echo htmlspecialchars($user['account_number'] ?? '—'); ?>
                        </span>
                    </div>
                </div>

                <?php if ($gradesFetchErr !== null): ?>
                    <div class="empty-state">
                        <i class="fas fa-triangle-exclamation"></i>
                        <p>Unable to load your grades right now. Please try again later.</p>
                        <span class="debug"><?php echo htmlspecialchars($gradesFetchErr); ?></span>
                    </div>

                <?php elseif (empty($studentGrades)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No grades have been posted yet. Please check back later.</p>
                    </div>

                <?php else: ?>

                    <!-- GROUPS BY AY + QUARTER -->
                    <?php foreach ($groupedGrades as $group): ?>
                        <div class="quarter-group">
                            <div class="quarter-head">
                                <h3>
                                    <i class="fas fa-layer-group"></i>
                                    <?php echo htmlspecialchars($group['quarter']); ?>
                                </h3>
                                <span class="ay-badge">
                                    <i class="fas fa-calendar" style="margin-right:4px;"></i>
                                    S.Y. <?php echo htmlspecialchars($group['academic_year']); ?>
                                </span>
                            </div>

                            <div class="grades-wrap">
                                <table class="grades">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>ID</th>
                                            <th>Account #</th>
                                            <th>Grade Level</th>
                                            <th>Section</th>
                                            <th>Subject</th>
                                            <th>Quarter</th>
                                            <th>Grade</th>
                                            <th>Adviser</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $rowNo = 0; ?>
                                        <?php foreach ($group['rows'] as $row): ?>
                                            <tr>
                                                <td><?php echo ++$rowNo; ?></td>
                                                <td class="cell-id"><?php echo (int) ($row['id'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars($row['account_number'] ?? '—'); ?></td>
                                                <td><?php echo htmlspecialchars($row['grade_level'] ?? '—'); ?></td>
                                                <td><?php echo htmlspecialchars($row['section'] ?? '—'); ?></td>
                                                <td><?php echo htmlspecialchars($row['subject'] ?? '—'); ?></td>
                                                <td><?php echo htmlspecialchars($row['quarter'] ?? '—'); ?></td>
                                                <td class="cell-grade">
                                                    <?php echo (isset($row['grade']) && $row['grade'] !== null && $row['grade'] !== '')
                                                        ? htmlspecialchars((string) $row['grade'])
                                                        : '—'; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars(resolveAdviserSurname($pdo, (string) ($row['adviser'] ?? ''), $adviserSurnameCache)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>

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

        /* ── DEVTOOLS DETECTION ── */
        (function () {
            'use strict';

            let active = false;

            function setActive(state) {
                if (state === active) return;
                active = state;
                document.body.classList.toggle('devtools-active', state);
            }

            function detectByDebugger() {
                const t0 = performance.now();
                // eslint-disable-next-line no-debugger
                debugger;
                const t1 = performance.now();
                return (t1 - t0) > 100;
            }

            function detectBySize() {
                const w = window.outerWidth - window.innerWidth;
                const h = window.outerHeight - window.innerHeight;
                if (window.outerWidth === 0 && window.outerHeight === 0) return false;
                return (w > 160 || h > 160);
            }

            function tick() {
                setActive(detectByDebugger() || detectBySize());
            }

            tick();

            let resizeTimer = null;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(tick, 150);
            });

            setInterval(tick, 800);

            document.addEventListener('keydown', (e) => {
                const k = (e.key || '').toLowerCase();
                if (
                    e.key === 'F12' ||
                    (e.ctrlKey && e.shiftKey && ['i', 'j', 'c'].includes(k)) ||
                    (e.metaKey && e.altKey && ['i', 'j', 'c'].includes(k))
                ) {
                    setTimeout(tick, 200);
                }
            }, true);
        })();
    </script>
</body>

</html>