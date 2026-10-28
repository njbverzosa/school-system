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
            margin-bottom: 24px;
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
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quarter-head h3 i {
            color: var(--primary);
            font-size: 13px;
        }

        .quarter-head .ay-badge {
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
            background: #f1f5f9;
            border: 1px solid var(--line);
            color: var(--muted);
        }

        /* TABLE */
        .grades-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            -webkit-overflow-scrolling: touch;
        }

        table.grades {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        table.grades thead {
            background: #f1f5f9;
        }

        table.grades th,
        table.grades td {
            padding: 11px 14px;
            text-align: left;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        table.grades th {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #64748b;
        }

        table.grades tbody tr:last-child td {
            border-bottom: none;
        }

        table.grades tbody tr:hover {
            background: #f8fafc;
        }

        .cell-grade {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            color: var(--primary-dark);
            font-size: 15px;
        }

        .cell-id {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 13px;
        }

        /* EMPTY */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--muted);
            font-size: 14px;
        }

        .empty-state i {
            font-size: 38px;
            color: #cbd5e1;
            margin-bottom: 10px;
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

        /* =====================================================
           DEVTOOLS DETECTION OVERLAY
           Shown ONLY while DevTools is open, hidden when closed.
           ===================================================== */
        #devtools-overlay {
          display: none;
            position: fixed;
            inset: 0;
            z-index: 2147483647;
            background: #000 center/contain no-repeat;
            background-image: url('../Images/edit.jpg');
            cursor: not-allowed;
        }

        #devtools-overlay.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #devtools-overlay img {
            max-width: 100vw;
            max-height: 100vh;
            object-fit: contain;
            display: block;
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

            table.grades th,
            table.grades td {
                padding: 9px 11px;
                font-size: 13px;
            }

            .cell-grade {
                font-size: 14px;
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
    <!-- DEVTOOLS DETECTION OVERLAY (hidden unless DevTools is open) -->
    <div id="devtools-overlay" aria-hidden="true">
        <img src="../Images/edit.jpg"/>
    </div>

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
        /* =====================================================
           NAV DROPDOWN
           ===================================================== */
        const navToggle = document.getElementById('navToggle');
        const navDropdown = document.getElementById('navDropdown');

        navToggle?.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = navDropdown.classList.toggle('open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', (e) => {
            if (navDropdown && !navDropdown.contains(e.target) && e.target !== navToggle) {
                navDropdown.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });

        /* =====================================================
           DEVTOOLS DETECTION — SHOW/HIDE OVERLAY
           Overlay is shown ONLY while DevTools is open.
           It is hidden again when DevTools is closed.
           ===================================================== */
        (function() {
            'use strict';

            const overlay = document.getElementById('devtools-overlay');
            if (!overlay) return;

            let shown = false;

            function setShown(state, reason) {
                if (state === shown) return;
                shown = state;

                if (state) {
                    overlay.classList.add('active');
                    overlay.setAttribute('aria-hidden', 'false');
                } else {
                    overlay.classList.remove('active');
                    overlay.setAttribute('aria-hidden', 'true');
                }

                // eslint-disable-next-line no-console
                console.warn('[devtools-detector]', state ? 'OPEN' : 'CLOSED', reason || '');
            }

            /* ---------------------------------------------
               A) Size-based detection (docked DevTools)
               DevTools docked right/bottom shrinks the
               viewport while the outer window stays the same.
               The threshold catches that gap.
               --------------------------------------------- */
            const THRESHOLD = 160; // px

            function detectBySize() {
                const wDelta = window.outerWidth  - window.innerWidth;
                const hDelta = window.outerHeight - window.innerHeight;

                // Guard against zero-sized outer window (some browsers report 0)
                if (window.outerWidth === 0 && window.outerHeight === 0) {
                    return false;
                }

                return (wDelta > THRESHOLD || hDelta > THRESHOLD);
            }

            /* ---------------------------------------------
               B) Debugger-timing detection (undocked DevTools)
               A `debugger` statement only pauses when a debugger
               is attached. When paused, performance.now() around
               it returns a large delta.
               --------------------------------------------- */
            function detectByDebugger() {
                const t0 = performance.now();
                // eslint-disable-next-line no-debugger
                debugger;
                const t1 = performance.now();
                return (t1 - t0) > 100; // ms — paused = big gap
            }

            /* ---------------------------------------------
               C) Console getter trap
               If DevTools console is open, accessing the id
               getter of the logged object triggers a pause.
               This is a best-effort fallback for Chrome/Edge.
               --------------------------------------------- */
            let trapObject = {};
            let trapFired = false;

            Object.defineProperty(trapObject, 'id', {
                get: function() {
                    trapFired = true;
                    return 'trap';
                }
            });

            function runTrap() {
                trapFired = false;
                // eslint-disable-next-line no-console
                console.log(trapObject);
                // If DevTools console rendered it, the getter would have fired.
                // Note: not 100% reliable, but useful as a fallback.
            }

            /* ---------------------------------------------
               MASTER LOOP — runs detection, updates overlay
               --------------------------------------------- */
            function tick() {
                const isOpen = detectBySize() || detectByDebugger();

                if (isOpen) {
                    setShown(true, detectBySize() ? 'size' : 'debugger');
                } else {
                    setShown(false, 'closed');
                }
            }

            // Re-check on resize (docking / undocking triggers resize)
            let resizeTimer = null;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(tick, 150);
            });

            // Periodic check — every 500ms while page is active
            const intervalId = setInterval(tick, 500);

            // Clean up if the page is hidden (saves CPU)
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    clearInterval(intervalId);
                } else {
                    tick();
                    // Restart interval after returning to the tab
                    // (we re-create it to avoid holding a stale interval)
                    setTimeout(() => {
                        setInterval(tick, 500);
                    }, 100);
                }
            });

            // First run
            tick();
        })();
    </script>
</body>

</html>