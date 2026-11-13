<?php
if (!isset($page_title)) $page_title = 'Dashboard';
if (!isset($show_header)) $show_header = true;

$is_admin = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
$base_path = $is_admin ? '../' : '';
$admin_path = $is_admin ? '' : 'admin/';
$current_page = basename($_SERVER['PHP_SELF']);

// Halaman turunan menandai menu induknya sebagai aktif di sidebar
$__nav_parent = ['health.php' => 'monitoring.php', 'license-details.php' => 'licenses.php', 'generate.php' => 'licenses.php'];
$__nav_current = $__nav_parent[$current_page] ?? $current_page;

// Tampilan singkat untuk key panjang (mis. APEIRON-…371EB631). Hanya untuk tampilan.
if (!function_exists('ui_mask_key')) {
    function ui_mask_key($key): string {
        $key = (string) $key;
        $len = strlen($key);
        if ($len <= 20) return $key;
        return substr($key, 0, 8) . '…' . substr($key, -8);
    }
}

// Dipakai footer.php untuk menutup kerangka sidebar dengan benar
$__app_shell_open = (bool) $show_header;

$__nav_username = (string) ($_SESSION['admin_username'] ?? 'Admin');
$__nav_initial = function_exists('mb_substr') ? mb_substr($__nav_username, 0, 1) : substr($__nav_username, 0, 1);
$__nav_role = (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin') ? 'Super Admin' : 'Administrator';

$__nav_main = [
    ['index.php', 'fa-th-large', 'Dashboard'],
    ['licenses.php', 'fa-id-card', 'Licenses'],
    ['analytics.php', 'fa-chart-bar', 'Analytics'],
    ['activations.php', 'fa-plug', 'Activations'],
    ['requests.php', 'fa-satellite-dish', 'API Requests'],
    ['api-keys.php', 'fa-key', 'API Keys'],
];
$__nav_more = [
    ['remote-deactivate.php', 'fa-power-off', 'Remote Deactivate'],
    ['monitoring.php', 'fa-heartbeat', 'Monitoring'],
    ['logs.php', 'fa-history', 'Activity Logs'],
    ['settings.php', 'fa-cog', 'Settings'],
];
if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin') {
    $__nav_more[] = ['admins.php', 'fa-users-cog', 'Manage Admins'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#16234b">
    <title><?= htmlspecialchars($page_title) ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&family=Ubuntu+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $base_path ?>assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script>
        // Terapkan tema sebelum halaman dirender agar tidak berkedip
        (function() {
            var savedTheme = null;
            try { savedTheme = localStorage.getItem('theme'); } catch (e) {}
            if (savedTheme !== 'dark' && savedTheme !== 'light') savedTheme = 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
</head>
<body>
    <?php if ($show_header): ?>
    <div class="app-shell">
        <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

        <aside class="sidebar" id="navMenu">
            <div class="sidebar-brand">
                <a href="<?= $admin_path ?>index.php" class="brand-link">
                    <span class="brand-mark"><i class="fas fa-infinity"></i></span>
                    <span class="brand-text">
                        <strong>Apeiron</strong>
                        <small>License Server</small>
                    </span>
                </a>
                <button class="sidebar-close" id="sidebarClose" type="button" aria-label="Tutup menu">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <nav class="sidebar-nav" aria-label="Navigasi utama">
                <p class="nav-label">Menu</p>
                <ul class="nav-group">
                    <?php foreach ($__nav_main as $__item): ?>
                    <li>
                        <a href="<?= $admin_path . $__item[0] ?>" class="nav-link <?= $__nav_current === $__item[0] ? 'active' : '' ?>">
                            <i class="fas <?= $__item[1] ?>"></i> <span><?= $__item[2] ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <p class="nav-label">Lainnya</p>
                <ul class="nav-group">
                    <?php foreach ($__nav_more as $__item): ?>
                    <li>
                        <a href="<?= $admin_path . $__item[0] ?>" class="nav-link <?= $__nav_current === $__item[0] ? 'active' : '' ?>">
                            <i class="fas <?= $__item[1] ?>"></i> <span><?= $__item[2] ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <div class="sidebar-footer">
                <a href="<?= $base_path ?>auth/logout.php" class="nav-link nav-link-danger">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </div>
        </aside>

        <div class="app-main">
            <header class="topbar">
                <button class="nav-burger" id="navBurger" type="button" aria-label="Buka menu" aria-controls="navMenu" aria-expanded="false">
                    <i class="fas fa-bars"></i>
                </button>

                <span class="topbar-title"><?= htmlspecialchars($page_title) ?></span>

                <div class="nav-user">
                    <button class="btn-theme-toggle" id="themeToggle" title="Ganti tema" aria-label="Ganti tema" type="button">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>

                    <span class="nav-username">
                        <span class="user-avatar"><?= htmlspecialchars($__nav_initial) ?></span>
                        <span class="user-meta">
                            <span class="user-name"><?= htmlspecialchars($__nav_username) ?></span>
                            <span class="user-role"><?= $__nav_role ?></span>
                        </span>
                    </span>

                    <a href="<?= $base_path ?>auth/logout.php" class="btn-logout" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="logout-text">Logout</span>
                    </a>
                </div>
            </header>

    <script>
    (function() {
        function readTheme() {
            var t = null;
            try { t = localStorage.getItem('theme'); } catch (e) {}
            return t === 'dark' ? 'dark' : 'light';
        }

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            var icon = document.getElementById('themeIcon');
            if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }

        function toggleTheme(e) {
            if (e) e.preventDefault();
            var next = readTheme() === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem('theme', next); } catch (err) {}
            applyTheme(next);
        }

        // Tetap tersedia untuk kode lama yang mungkin memanggilnya
        window.toggleThemeHandler = toggleTheme;

        applyTheme(readTheme());

        function setSidebar(open) {
            document.body.classList.toggle('nav-open', open);
            var burger = document.getElementById('navBurger');
            if (burger) burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        document.addEventListener('DOMContentLoaded', function() {
            applyTheme(readTheme());

            var themeToggle = document.getElementById('themeToggle');
            if (themeToggle) themeToggle.addEventListener('click', toggleTheme);

            var burger = document.getElementById('navBurger');
            var overlay = document.getElementById('sidebarOverlay');
            var closeBtn = document.getElementById('sidebarClose');

            if (burger) burger.addEventListener('click', function() {
                setSidebar(!document.body.classList.contains('nav-open'));
            });
            if (overlay) overlay.addEventListener('click', function() { setSidebar(false); });
            if (closeBtn) closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                setSidebar(false);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') setSidebar(false);
            });
            window.addEventListener('resize', function() {
                if (window.innerWidth > 1023) setSidebar(false);
            });
        });
    })();
    </script>
    <?php endif; ?>

    <div class="container">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($_SESSION['success_message']) ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($_SESSION['error_message']) ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
