<?php
/**
 * Simple profile page for logged-in users.
 * This page lets the current session owner upload or replace only their profile image.
 */

require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/state_helpers.php';

header('Content-Type: text/html; charset=utf-8');

function profilePageEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function buildProfilePageInitials($name) {
    $parts = preg_split('/\s+/', trim((string) $name));
    $parts = array_values(array_filter($parts));
    if (count($parts) === 0) {
        return 'NA';
    }

    $first = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1));
    $last = strtoupper(substr((string) ($parts[count($parts) - 1] ?? ''), 0, 1));

    return trim($first . $last) !== '' ? ($first . $last) : 'NA';
}

function resolvePanelLinkByRole($role) {
    switch (strtolower(trim((string) $role))) {
        case 'admin':
            return 'html/adminpanel.html';
        case 'hr':
            return 'html/hrpanel.html';
        case 'dean':
            return 'html/daenpanel.html';
        case 'professor':
            return 'html/profesorpanel.html';
        case 'vpaa':
            return 'html/vpaapanel.html';
        case 'osa':
            return 'html/osapanel.html';
        case 'student':
            return 'html/studentpanel.html';
        default:
            return 'html/mainpage.html';
    }
}

startNaapSession();
if (!isNaapAuthenticatedSession($pdo)) {
    destroyNaapSession();
    header('Location: html/mainpage.html');
    exit();
}
touchNaapActiveSession($pdo, getNaapSessionUserId(), getNaapActiveSessionToken());

runProfileImageMigrationsIfNeeded($pdo);

$currentUser = buildUserSnapshotById($pdo, getNaapSessionUserId(), false);
if (!$currentUser || normalizeLookupValue($currentUser['status'] ?? 'active') === 'inactive') {
    destroyNaapSession($pdo);
    header('Location: html/mainpage.html');
    exit();
}

$message = '';
$messageType = '';

// Handle the upload form submission.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedToken = trim((string) ($_POST['csrf_token'] ?? ''));
    if ($submittedToken === '' || !hash_equals(getNaapCsrfToken(), $submittedToken)) {
        $message = 'Invalid form token. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
        $uploadedFile = is_array($_FILES['profile_image'] ?? null) ? $_FILES['profile_image'] : null;
        if (!$uploadedFile) {
            $message = 'Please choose an image file to upload.';
            $messageType = 'error';
        } else {
            try {
                saveUploadedUserProfileImage($pdo, $currentUser['id'], $uploadedFile);
                $currentUser = buildUserSnapshotById($pdo, $currentUser['id'], false);
                $message = 'Profile image updated successfully.';
                $messageType = 'success';
            } catch (RuntimeException $error) {
                $message = $error->getMessage();
                $messageType = 'error';
            } catch (Throwable $error) {
                $message = 'Unable to update the profile image right now.';
                $messageType = 'error';
            }
        }
    }
}

$csrfToken = getNaapCsrfToken();
$profileImageUrl = trim((string) ($currentUser['profileImageUrl'] ?? $currentUser['photoData'] ?? ''));
$fullName = trim((string) ($currentUser['name'] ?? ''));
$role = trim((string) ($currentUser['role'] ?? ''));
$panelLink = resolvePanelLinkByRole($role);

$details = [
    'Full Name' => $fullName,
    'Email' => trim((string) ($currentUser['email'] ?? '')),
    'Role' => strtoupper($role),
    'Status' => strtoupper((string) ($currentUser['status'] ?? 'active')),
    'Campus' => trim((string) ($currentUser['campus'] ?? '')),
    'Department' => trim((string) ($currentUser['department'] ?? '')),
    'Program' => trim((string) ($currentUser['programName'] ?? '')),
    'Employee ID' => trim((string) ($currentUser['employeeId'] ?? '')),
    'Student Number' => trim((string) ($currentUser['studentNumber'] ?? '')),
    'Year / Section' => trim((string) ($currentUser['yearSection'] ?? '')),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="design/favicon-32x32.png">
    <link rel="apple-touch-icon" href="design/apple-touch-icon.png">
    <title>Profile Image</title>
    <style>
        :root {
            --bg: #f5efe4;
            --panel: rgba(255, 255, 255, 0.92);
            --ink: #1f2533;
            --muted: #6b7385;
            --line: rgba(31, 37, 51, 0.08);
            --accent: #0d6e6e;
            --accent-dark: #094c4c;
            --danger: #ae2d2d;
            --success: #1e7a45;
            --shadow: 0 24px 80px rgba(34, 38, 55, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(13, 110, 110, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(201, 133, 64, 0.15), transparent 30%),
                linear-gradient(180deg, #f7f2e9 0%, #efe6d6 100%);
            padding: 32px 18px;
        }

        .page {
            width: min(980px, 100%);
            margin: 0 auto;
            display: grid;
            gap: 24px;
        }

        .hero,
        .details,
        .upload-panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            box-shadow: var(--shadow);
        }

        .hero {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 24px;
            padding: 28px;
            align-items: center;
        }

        .photo-shell {
            width: 200px;
            height: 200px;
            border-radius: 32px;
            background: linear-gradient(145deg, rgba(13, 110, 110, 0.18), rgba(13, 110, 110, 0.05));
            border: 1px solid rgba(13, 110, 110, 0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .photo-shell img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .photo-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 54px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: var(--accent-dark);
        }

        .hero-copy h1 {
            margin: 0 0 10px;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.05;
        }

        .hero-copy p {
            margin: 0 0 12px;
            color: var(--muted);
            max-width: 60ch;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .hero-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 18px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
        }

        .btn-secondary {
            background: rgba(13, 110, 110, 0.09);
            color: var(--accent-dark);
        }

        .status-banner {
            margin: 0;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 600;
        }

        .status-banner.success {
            background: rgba(30, 122, 69, 0.12);
            color: var(--success);
            border: 1px solid rgba(30, 122, 69, 0.16);
        }

        .status-banner.error {
            background: rgba(174, 45, 45, 0.10);
            color: var(--danger);
            border: 1px solid rgba(174, 45, 45, 0.14);
        }

        .details,
        .upload-panel {
            padding: 24px;
        }

        .section-title {
            margin: 0 0 18px;
            font-size: 1.2rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
        }

        .detail-card {
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.78);
        }

        .detail-card .label {
            display: block;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .detail-card .value {
            font-size: 1rem;
            font-weight: 600;
            word-break: break-word;
        }

        .upload-panel form {
            display: grid;
            gap: 16px;
        }

        .upload-help {
            margin: 0;
            color: var(--muted);
        }

        input[type="file"] {
            width: 100%;
            padding: 14px;
            border-radius: 16px;
            border: 1px dashed rgba(13, 110, 110, 0.35);
            background: rgba(255, 255, 255, 0.92);
        }

        .submit-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        button[type="submit"] {
            min-height: 46px;
            padding: 0 20px;
            border: 0;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        .file-note {
            color: var(--muted);
            font-size: 0.95rem;
        }

        @media (max-width: 760px) {
            .hero {
                grid-template-columns: 1fr;
                justify-items: center;
                text-align: center;
            }

            .hero-actions {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <?php if ($message !== ''): ?>
            <p class="status-banner <?php echo $messageType === 'success' ? 'success' : 'error'; ?>">
                <?php echo profilePageEscape($message); ?>
            </p>
        <?php endif; ?>

        <section class="hero">
            <div class="photo-shell">
                <?php if ($profileImageUrl !== ''): ?>
                    <img src="<?php echo profilePageEscape($profileImageUrl); ?>" alt="Current profile image">
                <?php else: ?>
                    <div class="photo-fallback"><?php echo profilePageEscape(buildProfilePageInitials($fullName)); ?></div>
                <?php endif; ?>
            </div>

            <div class="hero-copy">
                <h1><?php echo profilePageEscape($fullName !== '' ? $fullName : 'Profile'); ?></h1>
                <p>
                    This page reads your account from the active PHP session, shows your saved user information,
                    and lets you upload a new profile image stored with your account in the database.
                </p>
                <div class="hero-actions">
                    <a class="btn-primary" href="<?php echo profilePageEscape($panelLink); ?>">Back To Panel</a>
                    <a class="btn-secondary" href="html/mainpage.html">Go To Login Page</a>
                </div>
            </div>
        </section>

        <section class="details">
            <h2 class="section-title">Current User Information</h2>
            <div class="detail-grid">
                <?php foreach ($details as $label => $value): ?>
                    <article class="detail-card">
                        <span class="label"><?php echo profilePageEscape($label); ?></span>
                        <span class="value"><?php echo profilePageEscape($value !== '' ? $value : 'Not available'); ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="upload-panel">
            <h2 class="section-title">Upload Or Replace Profile Image</h2>
            <p class="upload-help">
                Select only one image. Allowed file types: JPG, JPEG, PNG, and WEBP. Maximum size: 2MB.
                When you upload a new image, the previous database photo is replaced automatically.
            </p>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo profilePageEscape($csrfToken); ?>">
                <input type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>

                <div class="submit-row">
                    <button type="submit">Upload Profile Image</button>
                    <span class="file-note">The image is linked to your existing user record using your logged-in user ID.</span>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
