<?php
// student/student-library.php
// Shows class updates grouped as subject folders
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
   CURRENT ACADEMIC YEAR
   ------------------------------------------------------------ */
function currentAcademicYear(): string
{
    $y = (int) date('Y');
    return ((int) date('n') < 6) ? (($y - 1) . '-' . $y) : ($y . '-' . ($y + 1));
}
$currentYear = currentAcademicYear();

/* ------------------------------------------------------------
   RESOLVE STUDENT'S GRADE LEVEL + SECTION
   ------------------------------------------------------------ */
$effectiveGrade = '';
$effectiveSection = '';

if ($isLoggedIn && $user) {
    try {
        $stmt = $pdo->prepare("
            SELECT grade_level, section
            FROM enrolled_students
            WHERE account_number = ?
              AND academic_year = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$user['account_number'], $currentYear]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            $stmt = $pdo->prepare("
                SELECT grade_level, section
                FROM enrolled_students
                WHERE account_number = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$user['account_number']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($row) {
            $effectiveGrade = trim((string) ($row['grade_level'] ?? ''));
            $effectiveSection = trim((string) ($row['section'] ?? ''));
        }
    } catch (PDOException $e) {
        error_log("enrollment lookup failed: " . $e->getMessage());
    }

    if ($effectiveGrade === '' && !empty($user['grade_level'])) {
        $effectiveGrade = trim((string) $user['grade_level']);
    }
}

/* ------------------------------------------------------------
   CURRENT SUBJECT (folder click) via ?subject=...
   ------------------------------------------------------------ */
$currentSubject = trim((string) ($_GET['subject'] ?? ''));

/* ------------------------------------------------------------
   FETCH ALL CLASS UPDATES FOR THIS GRADE + SECTION
   (grouped by subject in PHP — no extra query per folder)
   ------------------------------------------------------------ */
$classUpdates = [];
$updatesFetchErr = null;

if ($isLoggedIn && $user && $effectiveGrade !== '' && $effectiveSection !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, grade_level, section, subject, file_name, type, modified_at
            FROM class_updates
            WHERE CAST(grade_level AS CHAR) = ?
              AND section = ?
            ORDER BY subject ASC, modified_at DESC, id DESC
        ");
        $stmt->execute([$effectiveGrade, $effectiveSection]);
        $classUpdates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        $updatesFetchErr = $e->getMessage();
        error_log("class updates fetch failed: " . $updatesFetchErr);
    }
}

/* Group by subject */
$bySubject = [];
foreach ($classUpdates as $f) {
    $subj = trim((string) ($f['subject'] ?? ''));
    if ($subj === '')
        $subj = 'Uncategorized';
    if (!isset($bySubject[$subj]))
        $bySubject[$subj] = [];
    $bySubject[$subj][] = $f;
}

/* If a subject is selected, keep only that subject's files */
$filesForCurrentSubject = [];
if ($currentSubject !== '' && isset($bySubject[$currentSubject])) {
    $filesForCurrentSubject = $bySubject[$currentSubject];
}

/* ------------------------------------------------------------
   HELPERS
   ------------------------------------------------------------ */
function fileIconClass(string $type): string
{
    $t = strtolower(trim($type));
    return match ($t) {
        'pdf' => 'fa-file-pdf',
        'doc', 'docx', 'docs' => 'fa-file-word',
        'xls', 'xlsx', 'excel' => 'fa-file-excel',
        'ppt', 'pptx' => 'fa-file-powerpoint',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'image' => 'fa-file-image',
        'zip', 'rar', '7z' => 'fa-file-zipper',
        'mp4', 'mov', 'avi', 'video' => 'fa-file-video',
        'mp3', 'wav', 'audio' => 'fa-file-audio',
        'txt' => 'fa-file-lines',
        'link', 'url' => 'fa-link',
        default => 'fa-file',
    };
}

function fileTypeColor(string $type): string
{
    $t = strtolower(trim($type));
    return match ($t) {
        'pdf' => '#dc2626',
        'doc', 'docx', 'docs' => '#2563eb',
        'xls', 'xlsx', 'excel' => '#16a34a',
        'ppt', 'pptx' => '#ea580c',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'image' => '#7c3aed',
        'zip', 'rar', '7z' => '#a16207',
        'mp4', 'mov', 'avi', 'video' => '#db2777',
        'mp3', 'wav', 'audio' => '#0891b2',
        'txt' => '#475569',
        'link', 'url' => '#0ea5e9',
        default => '#64748b',
    };
}

function buildDownloadUrl(array $f): string
{
    $name = trim((string) ($f['file_name'] ?? ''));
    $type = trim((string) ($f['type'] ?? ''));
    if ($name === '')
        return '#';

    $hasExt = pathinfo($name, PATHINFO_EXTENSION) !== '';
    $filename = $hasExt ? $name : ($name . ($type !== '' ? '.' . $type : ''));

    return '../uploads/class_updates/' . rawurlencode($filename);
}
?>
<!DOCTYPE html>
<html lang="fil">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>" />
    <title>Learning Links — Eguia National High School</title>
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

        /* BREADCRUMB */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--muted);
            padding: 10px 14px;
            background: #f8fafc;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .breadcrumb i {
            font-size: 12px;
            color: var(--primary);
        }

        .breadcrumb .sep {
            color: #cbd5e1;
        }

        .breadcrumb .crumb-active {
            color: var(--text);
            font-weight: 600;
        }

        .breadcrumb a.crumb-link {
            color: var(--primary-dark);
            font-weight: 600;
            transition: color .15s;
        }

        .breadcrumb a.crumb-link:hover {
            color: var(--primary);
        }

        /* FOLDER GRID */
        .folders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }

        .folder-card {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            padding: 18px 18px 16px;
            border-radius: var(--radius);
            border: 1px solid var(--line);
            background: linear-gradient(135deg, #f8fafc, #eef2ff);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            transition: border-color .15s, box-shadow .15s, transform .12s;
            position: relative;
        }

        .folder-card:hover {
            border-color: var(--primary-2);
            box-shadow: 0 10px 24px -12px rgba(30, 64, 175, .35);
            transform: translateY(-2px);
        }

        .folder-card:active {
            transform: scale(.98);
        }

        .folder-card .folder-icon {
            font-size: 40px;
            color: #f59e0b;
            /* amber folder color */
            flex-shrink: 0;
            line-height: 1;
        }

        .folder-card .folder-name {
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.25;
            word-break: break-word;
        }

        .folder-card .folder-count {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--muted);
            background: #fff;
            border: 1px solid var(--line);
            padding: 2px 10px;
            border-radius: 999px;
        }

        /* FILE LIST */
        .explorer {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .explorer-head,
        .file-row {
            display: grid;
            grid-template-columns: 40px minmax(0, 2.2fr) minmax(0, 1fr) 100px 150px 130px;
            gap: 10px;
            align-items: center;
        }

        .explorer-head {
            padding: 10px 14px;
            background: #f1f5f9;
            border-bottom: 1px solid var(--line);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #64748b;
        }

        .explorer-head .col-action {
            text-align: right;
        }

        .file-row {
            padding: 10px 14px;
            border-bottom: 1px solid var(--line);
            background: #fff;
            transition: background .12s;
        }

        .file-row:last-child {
            border-bottom: none;
        }

        .file-row:hover {
            background: #f8fafc;
        }

        .file-row .file-icon {
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border-radius: 6px;
            background: #f1f5f9;
            font-size: 15px;
        }

        .file-name {
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
        }

        .file-name .fname {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-name .fext {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .05em;
            font-weight: 600;
        }

        .file-subject {
            font-size: 13px;
            color: var(--text);
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-type-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 3px 10px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #f8fafc;
            color: var(--muted);
            width: fit-content;
        }

        .file-date {
            font-size: 12.5px;
            color: var(--muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* BUTTONS */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 8px 14px;
            border-radius: var(--radius);
            border: 1px solid var(--primary-dark);
            background: linear-gradient(180deg, var(--primary-2), var(--primary));
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none;
            transition: background .15s, transform .12s, box-shadow .15s;
            box-shadow: 0 4px 10px -4px rgba(29, 78, 216, .55);
        }

        .btn-primary:hover {
            background: linear-gradient(180deg, var(--primary), var(--primary-dark));
            box-shadow: 0 6px 14px -4px rgba(29, 78, 216, .65);
        }

        .btn-primary:active {
            transform: scale(.97);
        }

        .btn-primary i {
            font-size: 12px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12.5px;
        }

        .col-action {
            display: flex;
            justify-content: flex-end;
        }

        /* EMPTY */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--muted);
            font-size: 14px;
            border: 1px dashed var(--line);
            border-radius: var(--radius);
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

            .explorer-head,
            .file-row {
                grid-template-columns: 36px minmax(0, 2fr) minmax(0, 1fr) 100px 100px;
            }

            .explorer-head>.col-type,
            .file-row>.file-type-cell,
            .explorer-head>.col-date,
            .file-row>.file-date {
                display: none;
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

            .explorer-head,
            .file-row {
                grid-template-columns: 34px minmax(0, 2fr) minmax(0, 1fr) 100px;
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

            .folders-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .folder-card {
                padding: 14px 12px;
            }

            .folder-card .folder-icon {
                font-size: 32px;
            }

            .folder-card .folder-name {
                font-size: 13px;
            }

            .explorer-head {
                display: none;
            }

            .file-row {
                grid-template-columns: 34px minmax(0, 1fr) auto;
                grid-template-areas:
                    "icon name action"
                    "icon meta action";
                row-gap: 4px;
                column-gap: 10px;
                padding: 12px 14px;
            }

            .file-row .file-icon {
                grid-area: icon;
                align-self: start;
            }

            .file-name {
                grid-area: name;
            }

            .file-row .file-subject,
            .file-row .file-type-cell,
            .file-row .file-date {
                display: none;
            }

            .file-row .col-action {
                grid-area: action;
                align-self: center;
            }

            .file-name .fext {
                display: block;
            }

            .btn-primary {
                padding: 6px 10px;
                font-size: 12px;
            }

            .btn-primary .btn-label {
                display: none;
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

            .folders-grid {
                grid-template-columns: 1fr;
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

                        <a href="student-library.php" role="menuitem">
                            <i class="fas fa-book"></i> Learning Links
                        </a>

                        <a href="class-activities.php" role="menuitem">
                            <i class="fas fa-book-open"></i> Assignments &amp; Submission
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
                        <h2><i class="fas fa-book"></i> Learning Links</h2>
                        <span class="sub">
                            <?php echo htmlspecialchars($displayName); ?>
                            &middot; <?php echo htmlspecialchars($user['account_number'] ?? '—'); ?>
                        </span>
                    </div>

                    <?php if ($effectiveGrade !== '' || $effectiveSection !== ''): ?>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <?php if ($effectiveGrade !== ''): ?>
                                <span
                                    style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:4px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:var(--primary-dark);">
                                    <i class="fas fa-graduation-cap" style="font-size:11px;"></i>
                                    Grade <?php echo htmlspecialchars($effectiveGrade); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($effectiveSection !== ''): ?>
                                <span
                                    style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:4px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:var(--primary-dark);">
                                    <i class="fas fa-users" style="font-size:11px;"></i>
                                    <?php echo htmlspecialchars($effectiveSection); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- BREADCRUMB -->
                <div class="breadcrumb">
                    <i class="fas fa-hard-drive"></i>
                    <?php if ($currentSubject !== ''): ?>
                        <a href="student-library.php" class="crumb-link">Learning Links</a>
                        <span class="sep">/</span>
                        <span class="crumb-active"><?php echo htmlspecialchars($currentSubject); ?></span>
                    <?php else: ?>
                        <span>Learning Links</span>
                        <span class="sep">/</span>
                        <span class="crumb-active">All Subjects</span>
                    <?php endif; ?>
                </div>

                <?php if ($updatesFetchErr !== null): ?>
                    <div class="empty-state">
                        <i class="fas fa-triangle-exclamation"></i>
                        <p>Unable to load class files right now. Please try again later.</p>
                        <span class="debug"><?php echo htmlspecialchars($updatesFetchErr); ?></span>
                    </div>

                <?php elseif (empty($bySubject)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No learning materials have been posted for your section yet.</p>
                        <?php if ($effectiveGrade === '' || $effectiveSection === ''): ?>
                            <span class="debug">Your grade level or section could not be resolved.</span>
                        <?php endif; ?>
                    </div>

                <?php elseif ($currentSubject === '' || empty($filesForCurrentSubject)): ?>
                    <!-- ============================================================
                         FOLDER VIEW — one card per subject
                         ============================================================ -->
                    <div class="folders-grid">
                        <?php foreach ($bySubject as $subjName => $files): ?>
                            <a class="folder-card" href="student-library.php?subject=<?php echo urlencode($subjName); ?>">
                                <i class="fas fa-folder folder-icon"></i>
                                <div class="folder-name"><?php echo htmlspecialchars($subjName); ?></div>
                                <div class="folder-count">
                                    <?php echo count($files); ?>
                                    file<?php echo count($files) === 1 ? '' : 's'; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <!-- ============================================================
                         FILES VIEW — files inside the selected subject folder
                         ============================================================ -->
                    <div class="explorer">
                        <div class="explorer-head">
                            <span></span>
                            <span>Name</span>
                            <span>Subject</span>
                            <span class="col-type">Type</span>
                            <span class="col-date">Modified</span>
                            <span class="col-action">Action</span>
                        </div>

                        <?php foreach ($filesForCurrentSubject as $f): ?>
                            <?php
                            $type = (string) ($f['type'] ?? '');
                            $icon = fileIconClass($type);
                            $color = fileTypeColor($type);
                            $downloadUrl = buildDownloadUrl($f);
                            ?>
                            <div class="file-row" title="<?php echo htmlspecialchars($f['file_name'] ?? ''); ?>">
                                <div class="file-icon" style="color: <?php echo $color; ?>;">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="file-name">
                                    <span class="fname"><?php echo htmlspecialchars($f['file_name'] ?? 'Untitled'); ?></span>
                                    <span class="fext"><?php echo htmlspecialchars($type !== '' ? $type : 'file'); ?></span>
                                </div>
                                <div class="file-subject">
                                    <?php echo htmlspecialchars($f['subject'] ?? '—'); ?>
                                </div>
                                <div class="file-type-cell">
                                    <span class="file-type-pill"><?php echo htmlspecialchars($type !== '' ? $type : '—'); ?></span>
                                </div>
                                <div class="file-date">
                                    <?php echo htmlspecialchars($f['modified_at'] ?? '—'); ?>
                                </div>
                                <div class="col-action">
                                    <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="btn-primary btn-sm" download
                                        title="Download <?php echo htmlspecialchars($f['file_name'] ?? 'file'); ?>">
                                        <i class="fas fa-download"></i>
                                        <span class="btn-label">Download</span>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
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
            if (navDropdown && !navDropdown.contains(e.target) && e.target !== navToggle) {
                navDropdown.classList.remove('open');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });
    </script>
</body>

</html>