<?php
// student/my-profile.php
// Shows: Student Info (personal, contact, guardian)
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
?>
<!DOCTYPE html>
<html lang="fil">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>" />
    <title>Eguia National High School — Dasol, Pangasinan</title>
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

        /* STUDENT INFO */
        .student-card {
            display: flex;
            align-items: center;
            gap: 24px;
            padding: 24px 20px;
            border-radius: var(--radius);
            border: 1px solid var(--line);
            background: linear-gradient(135deg, #f8fafc, #eef2ff);
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .student-avatar {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid #fff;
            box-shadow: 0 8px 20px -8px rgba(15, 23, 42, .35);
            flex-shrink: 0;
            background: #e2e8f0;
            display: grid;
            place-items: center;
        }

        .student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .student-avatar .avatar-fallback {
            font-size: 44px;
            color: #94a3b8;
        }

        .student-meta {
            min-width: 0;
            flex: 1;
        }

        .student-meta h2 {
            font-size: 22px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 4px;
            word-break: break-word;
        }

        .student-meta .account {
            font-size: 13.5px;
            color: var(--muted);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .student-meta .account i {
            color: var(--primary);
            font-size: 12px;
        }

        /* INFO GRID */
        .info-section {
            margin-bottom: 24px;
        }

        .info-section:last-child {
            margin-bottom: 0;
        }

        .info-section h3 {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-section h3 i {
            color: var(--primary);
            font-size: 13px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1px;
            background: var(--line);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .info-item {
            background: #fff;
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }

        .info-item.full {
            grid-column: 1 / -1;
        }

        .info-item .info-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #94a3b8;
        }

        .info-item .info-value {
            font-size: 14.5px;
            font-weight: 600;
            color: var(--text);
            word-break: break-word;
            line-height: 1.4;
        }

        .info-item .info-value.empty {
            color: #cbd5e1;
            font-weight: 400;
            font-style: italic;
        }

        /* SPINNER */
        .spinner-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 24px;
            background: rgba(10, 26, 51, 0.82);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            color: #fff;
            opacity: 1;
            transition: opacity .4s ease;
        }

        .spinner-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .spinner {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            border: 5px solid rgba(255, 255, 255, .18);
            border-top-color: #22d3ee;
            border-right-color: #3b82f6;
            animation: spin 0.9s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .spinner-overlay .spinner-title {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: .01em;
        }

        .spinner-overlay .spinner-sub {
            font-size: 13.5px;
            color: rgba(255, 255, 255, .75);
            max-width: 420px;
            text-align: center;
            padding: 0 20px;
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

            .student-card {
                gap: 16px;
                padding: 18px 14px;
            }

            .student-avatar {
                width: 84px;
                height: 84px;
            }

            .student-avatar .avatar-fallback {
                font-size: 34px;
            }

            .student-meta h2 {
                font-size: 18px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .info-item {
                padding: 10px 14px;
            }

            .info-item .info-value {
                font-size: 14px;
            }

            .spinner {
                width: 52px;
                height: 52px;
                border-width: 4px;
            }

            .spinner-overlay .spinner-title {
                font-size: 16px;
            }

            .spinner-overlay .spinner-sub {
                font-size: 12.5px;
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

                <!-- ================= STUDENT CARD ================= -->
                <div class="student-card">
                    <div class="student-avatar">
                        <?php
                        $profileFile = trim((string) ($user['profile_account'] ?? ''));

                        // Has a real filename (not the default placeholder)?
                        $hasProfile = $profileFile !== ''
                            && strcasecmp($profileFile, 'profile-image.jpg') !== 0;

                        // Absolute filesystem path to check the file exists on the server
                        $absProfile = __DIR__ . 'https://campuslink.villaruz-print-shop-and-general-merchandise.shop/Students_Profile/' . basename($profileFile);
                        $fileExists = $hasProfile && is_file($absProfile);

                        // Public URL (adjust domain/path if the folder is at the web root)
                        $profileUrl = $fileExists
                            ? 'https://campuslink.villaruz-print-shop-and-general-merchandise.shop/Students_Profile/' . rawurlencode($profileFile)
                            : '';
                        ?>
                        <?php if ($profileUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($profileUrl); ?>"
                                alt="<?php echo htmlspecialchars($displayName); ?>"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';">
                            <div class="avatar-fallback" style="display:none;"><i class="fas fa-user"></i></div>
                        <?php else: ?>
                            <div class="avatar-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                    </div>

                    <div class="student-meta">
                        <h2><?php echo htmlspecialchars($displayName !== '' ? $displayName : 'Student'); ?></h2>
                        <span class="account">
                            <i class="fas fa-id-card"></i>
                            <?php echo htmlspecialchars($user['account_number'] ?? '—'); ?>
                        </span>
                        <span class="account">
                            <i class="fas fa-key"></i>
                            <?php echo htmlspecialchars($user['pin_text'] ?? '—'); ?>
                        </span>
                    </div>
                </div>

                <!-- PERSONAL INFORMATION -->
                <div class="info-section">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">First Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['first_name'] ?? '—'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Middle Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['middle_name'] ?? '—'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Surname</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['surname'] ?? '—'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Age</span>
                            <span class="info-value <?php echo empty($user['age']) ? 'empty' : ''; ?>">
                                <?php echo !empty($user['age']) ? htmlspecialchars((string) $user['age']) : 'Not provided'; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Sex</span>
                            <span class="info-value <?php echo empty($user['sex']) ? 'empty' : ''; ?>">
                                <?php echo !empty($user['sex']) ? htmlspecialchars($user['sex']) : 'Not provided'; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Birth Date</span>
                            <span class="info-value <?php echo empty($user['birth_date']) ? 'empty' : ''; ?>">
                                <?php echo !empty($user['birth_date']) ? htmlspecialchars($user['birth_date']) : 'Not provided'; ?>
                            </span>
                        </div>


                        <div class="info-item">
                            <span class="info-label">Contact Number</span>
                            <span class="info-value <?php echo empty($user['contact_number']) ? 'empty' : ''; ?>">
                                <?php echo !empty($user['contact_number']) ? htmlspecialchars($user['contact_number']) : 'Not provided'; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Birth Place</span>
                            <span class="info-value <?php echo empty($user['birth_place']) ? 'empty' : ''; ?>">
                                <?php echo !empty($user['birth_place']) ? htmlspecialchars($user['birth_place']) : 'Not provided'; ?>
                            </span>
                        </div>





                        <div class="info-item full">
                            <span class="info-label">Address</span>
                            <span class="info-value <?php echo empty($user['address']) ? 'empty' : ''; ?>">
                                <?php echo !empty($user['address']) ? htmlspecialchars($user['address']) : 'Not provided'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- CONTACT & GUARDIAN -->
                <div class="info-section">
                    <h3><i class="fas fa-address-book"></i> Contact &amp; Guardian</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Guardian</span>
                            <span class="info-value <?php echo empty($user['guardian']) ? 'empty' : ''; ?>">
                                <?php echo !empty($user['guardian']) ? htmlspecialchars($user['guardian']) : 'Not provided'; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Guardian Contact Number</span>
                            <span class="info-value <?php echo empty($user['guardian_contact_number']) ? 'empty' : ''; ?>">
                                <?php echo !empty($user['guardian_contact_number']) ? htmlspecialchars($user['guardian_contact_number']) : 'Not provided'; ?>
                            </span>
                        </div>
                    </div>
                </div>

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
            if (navDropdown && !navDropdown.contains(e.target) && e.target !== navToggle) {
                navDropdown.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });
    </script>
</body>

</html>