<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Redirect if already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: ../admin/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';

    // CSRF validation with auto-fallback for fresh sessions
    if (!validate_csrf_token($csrf_token) && !empty($_SESSION['csrf_token'])) {
        $error = 'Token keamanan tidak valid atau telah kadaluwarsa. Silakan muat ulang halaman.';
        error_log('CSRF token mismatch on login attempt.');
    } else {
        $username = trim(sanitize_input($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username/Email dan password harus diisi!';
        } else {
            try {
                $db = get_db_connection();
                $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                $admin = $stmt->fetch();

                if ($admin && verify_password($password, $admin['password_hash'])) {
                    regenerate_session_id();

                    try {
                        $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
                    } catch (Exception $e) {
                        // Non-critical, continue
                    }

                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_role'] = $admin['role'];
                    $_SESSION['admin_name'] = $admin['full_name'] ?: $admin['username'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['login_ip'] = get_client_ip();
                    $_SESSION['login_time'] = time();

                    generate_csrf_token();
                    log_activity('login', 'admin', $admin['id'], 'Admin logged in from ' . get_client_ip());

                    // Crucial: ensure session data is written to disk before redirect
                    session_write_close();

                    header('Location: ../admin/index.php');
                    exit;
                } else {
                    $error = 'Username/Email atau password salah!';
                    error_log('Failed login attempt for: ' . $username . ' from IP: ' . get_client_ip());
                }
            } catch (PDOException $e) {
                $error = 'Koneksi database gagal: ' . $e->getMessage();
                error_log('Database Error on Login: ' . $e->getMessage());
            } catch (Exception $e) {
                $error = 'Terjadi kesalahan: ' . $e->getMessage();
                error_log('Login Error: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#16234b">
    <title>Login — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&family=Ubuntu+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script>
        // Terapkan tema sebelum halaman dirender agar tidak berkedip
        (function() {
            var savedTheme = null;
            try { savedTheme = localStorage.getItem('theme'); } catch (e) {}
            if (savedTheme !== 'dark' && savedTheme !== 'light') savedTheme = 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <style>
        html, body {
            overflow-x: clip;
        }

        .auth {
            display: grid;
            grid-template-columns: 1fr;
            min-height: 100vh;
            min-height: 100dvh;
            background: var(--bg-page);
        }

        /* ---- Panel brand (kiri) ---- */
        .auth-aside {
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            background: linear-gradient(160deg, #16234b 0%, #1c2c5e 55%, #24397a 100%);
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .auth-aside::before,
        .auth-aside::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            opacity: 0.16;
            pointer-events: none;
        }

        .auth-aside::before {
            width: 420px;
            height: 420px;
            right: -140px;
            top: -120px;
            background: radial-gradient(circle, #2563eb, transparent 65%);
        }

        .auth-aside::after {
            width: 320px;
            height: 320px;
            left: -110px;
            bottom: -90px;
            background: radial-gradient(circle, #667085, transparent 65%);
        }

        .auth-intro {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
            width: 100%;
            max-width: 420px;
            text-align: center;
        }

        .auth-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .auth-brand .brand-mark {
            width: 42px;
            height: 42px;
            font-size: 18px;
            background: linear-gradient(135deg, #2563eb, #5b8dff);
        }

        .auth-brand .brand-text {
            text-align: left;
        }

        .auth-brand .brand-text strong {
            color: #fff;
        }

        .auth-brand .brand-text small {
            color: #98a2b3;
        }

        .auth-pitch h2 {
            font-size: 1.75rem;
            line-height: 1.3;
            color: #fff;
            text-wrap: balance;
        }

        .auth-points {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
            margin: 24px auto 0;
            width: max-content;
            max-width: 100%;
            list-style: none;
            text-align: left;
        }

        .auth-points li {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: var(--fs-body);
            color: #d5dcec;
        }

        .point-icon {
            display: grid;
            place-items: center;
            width: 30px;
            height: 30px;
            flex-shrink: 0;
            border-radius: var(--r-sm);
            background: rgba(255, 255, 255, 0.1);
            color: #8fb4ff;
            font-size: 13px;
        }

        .auth-foot {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 32px;
            z-index: 1;
            text-align: center;
            font-size: var(--fs-caption);
            color: #7f8db0;
        }

        /* ---- Form (kanan) ---- */
        .auth-main {
            position: relative;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .auth-theme {
            position: absolute;
            top: 20px;
            right: 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 420px;
            padding: 16px 0;
        }

        .auth-mobile-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
        }

        .auth-head {
            margin-bottom: 28px;
        }

        .auth-head h1 {
            font-size: var(--fs-h1);
            margin-bottom: 8px;
        }

        .auth-head p {
            color: var(--text-muted);
        }

        .auth-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .auth-form .form-group {
            margin-bottom: 0;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-group .form-control {
            height: var(--h-control-lg);
            padding-left: 40px;
        }

        .input-group .form-control.has-toggle {
            padding-right: 44px;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 14px;
            transform: translateY(-50%);
            color: var(--text-faint);
            font-size: 14px;
            pointer-events: none;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            display: grid;
            place-items: center;
            width: 30px;
            height: 30px;
            padding: 0;
            border: none;
            border-radius: var(--r-sm);
            background: none;
            color: var(--text-faint);
            cursor: pointer;
            font-size: 14px;
            transition: color var(--dur) var(--ease);
        }

        .password-toggle:hover {
            color: var(--text-body);
        }

        .auth-error {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: var(--err-soft);
            border: 1px solid var(--err-border);
            border-radius: var(--r);
            font-size: var(--fs-label);
            font-weight: var(--fw-medium);
            color: var(--err);
        }

        .auth-submit {
            width: 100%;
            height: var(--h-control-lg);
            font-size: var(--fs-body);
        }

        .auth-alt {
            margin-top: 28px;
            text-align: center;
            font-size: var(--fs-caption);
            color: var(--text-muted);
        }

        @media (min-width: 900px) {
            .auth {
                grid-template-columns: 1.05fr 1fr;
            }

            .auth-aside {
                display: flex;
            }

            .auth-mobile-brand {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="auth">
        <aside class="auth-aside">
            <div class="auth-intro">
                <div class="auth-brand">
                    <span class="brand-mark"><i class="fas fa-infinity"></i></span>
                    <span class="brand-text">
                        <strong>Apeiron</strong>
                        <small>License Server</small>
                    </span>
                </div>

                <div class="auth-pitch">
                    <h2>Kelola lisensi Apeiron dari satu tempat.</h2>
                    <ul class="auth-points">
                        <li><span class="point-icon"><i class="fas fa-shield-alt"></i></span> Lisensi terverifikasi &amp; aman</li>
                        <li><span class="point-icon"><i class="fas fa-plug"></i></span> Pantau aktivasi setiap situs</li>
                        <li><span class="point-icon"><i class="fas fa-chart-bar"></i></span> Analitik dan log aktivitas</li>
                    </ul>
                </div>
            </div>

            <p class="auth-foot">&copy; <?= date('Y') ?> <?= APP_NAME ?></p>
        </aside>

        <main class="auth-main">
            <button class="btn-theme-toggle auth-theme" id="themeToggleLogin" type="button" title="Ganti tema" aria-label="Ganti tema">
                <i class="fas fa-moon" id="themeIconLogin"></i>
            </button>

            <div class="auth-card">
                <div class="auth-mobile-brand">
                    <span class="brand-mark"><i class="fas fa-infinity"></i></span>
                    <span class="brand-text">
                        <strong>Apeiron</strong>
                        <small>License Server</small>
                    </span>
                </div>

                <header class="auth-head">
                    <h1>Selamat datang kembali</h1>
                    <p>Masuk ke admin panel <?= APP_NAME ?>.</p>
                </header>

                <form method="POST" id="loginForm" class="auth-form">
                    <?php if ($error): ?>
                        <div class="auth-error" role="alert">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                    <div class="form-group">
                        <label class="form-label" for="username">Username atau Email</label>
                        <div class="input-group">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" name="username" id="username" class="form-control" placeholder="Masukkan username" autocomplete="username" required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" id="password" class="form-control has-toggle" placeholder="Masukkan password" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" id="passwordToggle" aria-label="Tampilkan password">
                                <i class="fas fa-eye" id="passwordToggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary auth-submit">
                        Masuk
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>

                <p class="auth-alt"><?= APP_NAME ?> &copy; <?= date('Y') ?></p>
            </div>
        </main>
    </div>

    <script>
        (function() {
            function readTheme() {
                var t = null;
                try { t = localStorage.getItem('theme'); } catch (e) {}
                return t === 'dark' ? 'dark' : 'light';
            }

            function applyTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                var icon = document.getElementById('themeIconLogin');
                if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            }

            document.addEventListener('DOMContentLoaded', function() {
                applyTheme(readTheme());

                var themeToggle = document.getElementById('themeToggleLogin');
                if (themeToggle) {
                    themeToggle.addEventListener('click', function() {
                        var next = readTheme() === 'dark' ? 'light' : 'dark';
                        try { localStorage.setItem('theme', next); } catch (e) {}
                        applyTheme(next);
                    });
                }

                var pwToggle = document.getElementById('passwordToggle');
                if (pwToggle) {
                    pwToggle.addEventListener('click', function() {
                        var input = document.getElementById('password');
                        var icon = document.getElementById('passwordToggleIcon');
                        var show = input.type === 'password';
                        input.type = show ? 'text' : 'password';
                        icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
                        pwToggle.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
                    });
                }
            });
        })();
    </script>
</body>
</html>
