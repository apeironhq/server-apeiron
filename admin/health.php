<?php
/**
 * Health Check — tampilan admin
 *
 * Menampilkan hasil endpoint /api/health.php dalam bentuk yang mudah dibaca.
 * Endpoint JSON-nya sendiri tidak diubah, jadi alat monitoring yang
 * membacanya tetap bekerja seperti biasa.
 */

require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Health Check';
$show_header = true;

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-heartbeat"></i> Health Check
        </h1>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="monitoring.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left"></i> Monitoring
            </a>
            <button type="button" class="btn btn-sm btn-secondary" id="healthRefresh">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <a href="../api/health.php" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
                <i class="fas fa-code"></i> Raw JSON
            </a>
        </div>
    </div>

    <div class="health-hero" id="healthHero">
        <span class="health-hero-icon"><i class="fas fa-circle-notch fa-spin" id="healthHeroIcon"></i></span>
        <div class="health-hero-text">
            <h2 id="healthStatus">Memeriksa…</h2>
            <p id="healthMeta">Mengambil status dari /api/health.php</p>
        </div>
        <span class="badge" id="healthBadge" style="display: none;"></span>
    </div>
</div>

<div class="health-grid" id="healthChecks"></div>

<noscript>
    <div class="alert alert-warning">
        JavaScript nonaktif. Buka <a href="../api/health.php">/api/health.php</a> untuk melihat data mentah.
    </div>
</noscript>

<script>
(function() {
    var LABELS = {
        database:   { name: 'Database',          icon: 'fa-database' },
        cache:      { name: 'Cache Directory',   icon: 'fa-folder-open' },
        rate_limit: { name: 'Rate Limit Storage', icon: 'fa-tachometer-alt' },
        php:        { name: 'PHP',               icon: 'fa-code' },
        extensions: { name: 'PHP Extensions',    icon: 'fa-puzzle-piece' },
        disk:       { name: 'Disk Space',        icon: 'fa-hdd' }
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function tone(status) {
        if (status === 'ok' || status === 'healthy') return 'ok';
        if (status === 'warning') return 'warn';
        if (status === 'error' || status === 'unhealthy') return 'err';
        return '';
    }

    function badgeClass(t) {
        return t === 'ok' ? 'badge-success' : t === 'warn' ? 'badge-warning' : t === 'err' ? 'badge-danger' : 'badge-secondary';
    }

    function detail(key, c) {
        var parts = [];
        if (key === 'database') {
            if (c.error) parts.push(c.error);
            else if (c.response_time_ms !== undefined) parts.push('Koneksi berhasil');
        } else if (key === 'cache' || key === 'rate_limit') {
            parts.push(c.writable ? 'Dapat ditulis' : 'Tidak dapat ditulis');
        } else if (key === 'php') {
            parts.push('Versi ' + c.version);
        } else if (key === 'extensions') {
            if (c.loaded) parts.push(c.loaded.join(', '));
            if (c.missing) parts.push('Tidak ada: ' + c.missing.join(', '));
        } else if (key === 'disk') {
            parts.push(c.free_percent + '% kosong');
            if (c.free_space_mb !== undefined) parts.push((c.free_space_mb / 1024).toFixed(1) + ' GB tersisa');
        } else {
            Object.keys(c).forEach(function(k) {
                if (k !== 'status') parts.push(k + ': ' + (typeof c[k] === 'object' ? JSON.stringify(c[k]) : c[k]));
            });
        }
        return parts.join(' · ');
    }

    function setHero(t, title, meta, badge) {
        var hero = document.getElementById('healthHero');
        hero.className = 'health-hero' + (t ? ' is-' + t : '');
        document.getElementById('healthHeroIcon').className =
            'fas ' + (t === 'ok' ? 'fa-check-circle' : t === 'warn' ? 'fa-exclamation-triangle' : t === 'err' ? 'fa-times-circle' : 'fa-circle-notch fa-spin');
        document.getElementById('healthStatus').textContent = title;
        document.getElementById('healthMeta').textContent = meta;
        var b = document.getElementById('healthBadge');
        if (badge) {
            b.className = 'badge ' + badgeClass(t);
            b.textContent = badge;
            b.style.display = '';
        } else {
            b.style.display = 'none';
        }
    }

    function render(data) {
        var checks = data.checks || {};
        var keys = Object.keys(checks);
        var warn = keys.filter(function(k) { return checks[k].status === 'warning'; }).length;
        var overall = tone(data.status);
        if (overall === 'ok' && warn) overall = 'warn';

        var title = data.status === 'healthy' ? (warn ? 'Sehat, dengan peringatan' : 'Semua sistem normal') : 'Ada masalah';
        setHero(overall, title,
            'Versi ' + esc(data.version) + ' · Diperiksa ' + esc(data.timestamp) + ' · ' + keys.length + ' pemeriksaan',
            data.status === 'healthy' ? 'Healthy' : 'Unhealthy');

        document.getElementById('healthChecks').innerHTML = keys.map(function(k) {
            var c = checks[k] || {};
            var t = tone(c.status);
            var meta = LABELS[k] || { name: k, icon: 'fa-check' };
            return '<div class="health-check is-' + t + '">' +
                '<span class="health-check-icon"><i class="fas ' + meta.icon + '"></i></span>' +
                '<div class="health-check-body">' +
                    '<div class="health-check-head">' +
                        '<span class="health-check-name">' + esc(meta.name) + '</span>' +
                        '<span class="badge ' + badgeClass(t) + '">' + esc(String(c.status || '-').toUpperCase()) + '</span>' +
                    '</div>' +
                    '<div class="health-check-detail">' + esc(detail(k, c)) + '</div>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    function load() {
        setHero('', 'Memeriksa…', 'Mengambil status dari /api/health.php', '');
        fetch('../api/health.php', { cache: 'no-store', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(render)
            .catch(function() {
                setHero('err', 'Tidak dapat memuat status', 'Endpoint /api/health.php tidak merespons dengan benar.', 'Error');
                document.getElementById('healthChecks').innerHTML = '';
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('healthRefresh');
        if (btn) btn.addEventListener('click', load);
        load();
    });
})();
</script>

<?php include '../includes/footer.php'; ?>
