<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Dashboard';
$show_header = true;

// Get statistics
$stats = get_license_stats();

// Get recent licenses
// FIX: Optimize query to avoid N+1 problem - use proper JOIN
try {
    $db = get_db_connection();
    $recent_licenses = $db->query("
        SELECT l.*,
               COUNT(DISTINCT a.id) as activation_count,
               ad.username as created_by_name
        FROM licenses l
        LEFT JOIN activations a ON l.id = a.license_id AND a.status = 'active'
        LEFT JOIN admins ad ON l.created_by = ad.id
        GROUP BY l.id
        ORDER BY l.created_at DESC
        LIMIT 5
    ")->fetchAll();

    // Get expiring soon
    $expiring_soon = $db->query("
        SELECT * FROM licenses
        WHERE expires IS NOT NULL
        AND expires BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND status = 'active'
        ORDER BY expires ASC
        LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {
    $recent_licenses = [];
    $expiring_soon = [];
}

// ---------------------------------------------------------------------------
// Data grafik dashboard (hanya baca). Disimpan di cache 5 menit supaya
// halaman tetap ringan walaupun tabel api_logs sudah besar.
// ---------------------------------------------------------------------------
if (!function_exists('cache_get') && file_exists(__DIR__ . '/../includes/cache.php')) {
    require_once __DIR__ . '/../includes/cache.php';
}

function dashboard_chart_data(): array {
    $data = [
        'days' => [],          // label tanggal 14 hari terakhir (Y-m-d)
        'req_ok' => [],
        'req_failed' => [],
        'activations' => [],
        'status' => [],        // status lisensi => jumlah
        'top_sites' => [],     // [host, total, failed] 7 hari terakhir
        'req_total' => 0,
        'req_failed_total' => 0,
        'generated_at' => date('Y-m-d H:i:s'),
    ];

    $index = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $index[$d] = count($data['days']);
        $data['days'][] = $d;
        $data['req_ok'][] = 0;
        $data['req_failed'][] = 0;
        $data['activations'][] = 0;
    }

    try {
        $db = get_db_connection();

        // Status lisensi
        foreach ($db->query("SELECT status, COUNT(*) AS c FROM licenses GROUP BY status")->fetchAll() as $r) {
            $data['status'][(string) $r['status']] = (int) $r['c'];
        }

        // Aktivasi baru per hari
        $stmt = $db->query("
            SELECT DATE(activated_at) AS d, COUNT(*) AS c
            FROM activations
            WHERE activated_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
            GROUP BY DATE(activated_at)
        ");
        foreach ($stmt->fetchAll() as $r) {
            if (isset($index[$r['d']])) $data['activations'][$index[$r['d']]] = (int) $r['c'];
        }
    } catch (Exception $e) {
        // biarkan kosong
    }

    // Request API per hari & website teratas (butuh kolom created_at di api_logs)
    try {
        $db = get_db_connection();
        $stmt = $db->query("
            SELECT DATE(created_at) AS d, SUM(status_code < 400) AS ok, SUM(status_code >= 400) AS failed
            FROM api_logs
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
            GROUP BY DATE(created_at)
        ");
        foreach ($stmt->fetchAll() as $r) {
            if (!isset($index[$r['d']])) continue;
            $i = $index[$r['d']];
            $data['req_ok'][$i] = (int) $r['ok'];
            $data['req_failed'][$i] = (int) $r['failed'];
        }
        $data['req_total'] = array_sum($data['req_ok']) + array_sum($data['req_failed']);
        $data['req_failed_total'] = array_sum($data['req_failed']);

        $stmt = $db->query("
            SELECT site_url, COUNT(*) AS total, SUM(status_code >= 400) AS failed
            FROM api_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND site_url IS NOT NULL AND site_url <> ''
            GROUP BY site_url
            ORDER BY total DESC
            LIMIT 50
        ");
        $hosts = [];
        foreach ($stmt->fetchAll() as $r) {
            $url = trim((string) $r['site_url']);
            $host = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'http://' . $url, PHP_URL_HOST);
            $host = strtolower($host ?: $url);
            if (!isset($hosts[$host])) $hosts[$host] = ['host' => $host, 'total' => 0, 'failed' => 0];
            $hosts[$host]['total'] += (int) $r['total'];
            $hosts[$host]['failed'] += (int) $r['failed'];
        }
        usort($hosts, function ($a, $b) { return $b['total'] <=> $a['total']; });
        $data['top_sites'] = array_slice(array_values($hosts), 0, 5);
    } catch (Exception $e) {
        // api_logs belum ada / tanpa created_at: grafik request tampil kosong
    }

    return $data;
}

$chart = function_exists('cache_get') ? cache_get('dashboard_charts_v1') : null;
if (!is_array($chart) || empty($chart['days'])) {
    $chart = dashboard_chart_data();
    if (function_exists('cache_set')) {
        @cache_set('dashboard_charts_v1', $chart, 300);
    }
}

include '../includes/header.php';

// ---------------------------------------------------------------------------
// Helper tampilan dashboard
// ---------------------------------------------------------------------------
$__days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$__months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$__today = $__days[(int) date('w')] . ', ' . date('j') . ' ' . $__months[(int) date('n')] . ' ' . date('Y');
$__admin = $_SESSION['admin_username'] ?? 'Admin';

$stat_cards = [
    ['href' => 'licenses.php', 'tone' => '', 'icon' => 'fa-certificate', 'value' => $stats['total'] ?? 0, 'label' => 'Total Licenses', 'hint' => 'Semua lisensi'],
    ['href' => 'licenses.php?status=active', 'tone' => 'success', 'icon' => 'fa-check-circle', 'value' => $stats['active'] ?? 0, 'label' => 'Active', 'hint' => 'Sedang aktif'],
    ['href' => 'licenses.php?status=inactive', 'tone' => 'danger', 'icon' => 'fa-times-circle', 'value' => $stats['inactive'] ?? 0, 'label' => 'Inactive', 'hint' => 'Belum diaktifkan'],
    ['href' => 'licenses.php?status=expired', 'tone' => 'warning', 'icon' => 'fa-calendar-times', 'value' => $stats['expired'] ?? 0, 'label' => 'Expired', 'hint' => 'Masa berlaku habis'],
    ['href' => 'activations.php', 'tone' => 'info', 'icon' => 'fa-link', 'value' => $stats['activations'] ?? 0, 'label' => 'Activations', 'hint' => 'Situs aktif'],
    ['href' => 'licenses.php?status=active', 'tone' => 'warning', 'icon' => 'fa-hourglass-half', 'value' => $stats['expiring_soon'] ?? 0, 'label' => 'Expiring Soon', 'hint' => '30 hari ke depan'],
];
?>

<div class="dash-hero">
    <div class="dash-hero-text">
        <h1>Selamat datang, <?= htmlspecialchars($__admin) ?></h1>
        <p><?= $__today ?> · Ringkasan lisensi dan aktivitas server</p>
    </div>
    <span class="dash-updated" title="Grafik diperbarui otomatis setiap 5 menit">
        <i class="fas fa-sync-alt"></i> Data grafik: <?= htmlspecialchars(format_date($chart['generated_at'], 'H:i')) ?>
    </span>
</div>

<div class="stats-grid">
    <?php foreach ($stat_cards as $card): ?>
    <a href="<?= htmlspecialchars($card['href']) ?>" class="stat-card stat-link <?= $card['tone'] ?>">
        <div class="stat-icon"><i class="fas <?= $card['icon'] ?>"></i></div>
        <div class="stat-label"><?= $card['label'] ?></div>
        <div class="stat-value"><?= number_format((int) $card['value']) ?></div>
        <div class="stat-change"><?= $card['hint'] ?></div>
    </a>
    <?php endforeach; ?>
</div>

<?php
$__status_total = array_sum($chart['status']);
$__status_meta = [
    'active' => ['Active', 'var(--brand)'],
    'inactive' => ['Inactive', 'var(--err)'],
    'expired' => ['Expired', 'var(--warn)'],
    'suspended' => ['Suspended', 'var(--n-400)'],
];
?>
<div class="dash-charts">
    <div class="card dash-card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-chart-bar"></i> Aktivitas API · 14 hari
            </h2>
            <div class="dash-legend">
                <span><i style="background: var(--brand);"></i> Sukses</span>
                <span><i style="background: var(--err);"></i> Gagal</span>
                <span><i class="is-line" style="background: #6a53b8;"></i> Aktivasi baru</span>
            </div>
        </div>
        <div class="dash-kpis">
            <div><strong><?= number_format($chart['req_total']) ?></strong><span>Total request</span></div>
            <div><strong class="<?= $chart['req_failed_total'] > 0 ? 'is-bad' : '' ?>"><?= number_format($chart['req_failed_total']) ?></strong><span>Request gagal</span></div>
            <div><strong><?= number_format(array_sum($chart['activations'])) ?></strong><span>Aktivasi baru</span></div>
        </div>
        <div class="dash-chart-box">
            <canvas id="dashRequestsChart" aria-label="Grafik request API 14 hari" role="img"></canvas>
        </div>
    </div>

    <div class="card dash-card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-chart-pie"></i> Status Lisensi
            </h2>
        </div>
        <div class="dash-donut">
            <div class="dash-donut-chart">
                <canvas id="dashStatusChart" aria-label="Grafik status lisensi" role="img"></canvas>
                <div class="dash-donut-center">
                    <strong><?= number_format($__status_total) ?></strong>
                    <span>lisensi</span>
                </div>
            </div>
            <ul class="dash-status-list">
                <?php foreach ($__status_meta as $key => $meta): ?>
                <?php $n = (int) ($chart['status'][$key] ?? 0); ?>
                <li>
                    <a href="licenses.php?status=<?= $key ?>">
                        <i style="background: <?= $meta[1] ?>;"></i>
                        <span><?= $meta[0] ?></span>
                        <strong><?= number_format($n) ?></strong>
                        <em><?= $__status_total > 0 ? round($n / $__status_total * 100) : 0 ?>%</em>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<div class="dashboard-grid dash-grid">
    <div class="card dash-card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-hourglass-half"></i> Expiring Soon
            </h2>
            <span class="badge badge-warning">30 hari</span>
        </div>
        <?php if (empty($expiring_soon)): ?>
            <p class="dash-empty"><i class="fas fa-check-circle"></i> Tidak ada lisensi yang akan habis dalam 30 hari.</p>
        <?php else: ?>
            <ul class="dash-list">
                <?php foreach ($expiring_soon as $license): ?>
                <?php
                    $days_left = (strtotime($license['expires']) - time()) / 86400;
                    $status_class = $days_left <= 7 ? 'danger' : 'warning';
                ?>
                <li>
                    <div class="dash-list-main">
                        <code class="key-chip"><?= htmlspecialchars(ui_mask_key($license['license_key'])) ?></code>
                        <span class="cell-sub key-chip" style="display: block;"><?= htmlspecialchars($license['customer_name'] ?: $license['product_id']) ?></span>
                    </div>
                    <div class="dash-list-side">
                        <span class="badge badge-<?= $status_class ?>"><?= max(0, (int) floor($days_left)) ?> days</span>
                        <span class="cell-sub"><?= format_date($license['expires'], 'Y-m-d') ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card dash-card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-globe"></i> Top Websites · 7 hari
            </h2>
            <a href="requests.php" class="btn btn-sm btn-secondary">Detail</a>
        </div>
        <?php if (empty($chart['top_sites'])): ?>
            <p class="dash-empty"><i class="fas fa-info-circle"></i> Belum ada request dalam 7 hari terakhir.</p>
        <?php else: ?>
            <?php $__top_max = max(1, (int) $chart['top_sites'][0]['total']); ?>
            <ul class="dash-bars">
                <?php foreach ($chart['top_sites'] as $site): ?>
                <li>
                    <div class="dash-bars-row">
                        <span class="key-chip" title="<?= htmlspecialchars($site['host']) ?>"><?= htmlspecialchars($site['host']) ?></span>
                        <span class="dash-bars-num">
                            <?= number_format($site['total']) ?>
                            <?php if ($site['failed'] > 0): ?>
                                <span class="badge badge-danger"><?= number_format($site['failed']) ?> gagal</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="dash-bars-track">
                        <span style="width: <?= round($site['total'] / $__top_max * 100) ?>%;"></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-clock"></i> Recent Licenses
        </h2>
        <a href="licenses.php" class="btn btn-sm btn-secondary">View All</a>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>License</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Activations</th>
                    <th>Expires</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_licenses)): ?>
                    <tr class="empty-row"><td colspan="6">Belum ada lisensi.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent_licenses as $license): ?>
                    <tr>
                        <td>
                            <?php // SECURITY: key disamarkan di tampilan ?>
                            <code class="key-chip"><?= htmlspecialchars(ui_mask_key($license['license_key'])) ?></code>
                            <span class="cell-sub"><?= htmlspecialchars($license['product_id']) ?></span>
                        </td>
                        <td>
                            <?php if (!empty($license['customer_name'])): ?>
                                <?= htmlspecialchars($license['customer_name']) ?>
                                <?php if (!empty($license['customer_email'])): ?>
                                    <span class="cell-sub"><?= htmlspecialchars($license['customer_email']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cell-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($license['status']) ?>">
                                <?= ucfirst(htmlspecialchars($license['status'])) ?>
                            </span>
                        </td>
                        <td><?= (int) $license['activation_count'] ?> / <?= $license['activation_limit'] ?: '∞' ?></td>
                        <td>
                            <?php if (!empty($license['expires'])): ?>
                                <?= format_date($license['expires'], 'Y-m-d') ?>
                            <?php else: ?>
                                <span class="cell-muted">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= time_ago($license['created_at']) ?>
                            <span class="cell-sub"><?= format_date($license['created_at'], 'Y-m-d') ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
(function() {
    if (typeof Chart === 'undefined') return;

    const css = (name, fallback) => (getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback);
    const C = {
        brand: css('--brand', '#2563eb'),
        err: css('--err', '#b42318'),
        warn: css('--warn', '#b54708'),
        neutral: css('--n-400', '#98a2b3'),
        lilac: '#6a53b8',
        text: css('--text-muted', '#667085'),
        grid: css('--border-soft', '#eceff4'),
        surface: css('--bg-surface', '#ffffff')
    };
    Chart.defaults.font.family = css('--font-sans', 'Ubuntu, sans-serif');
    Chart.defaults.color = C.text;
    Chart.defaults.borderColor = C.grid;

    const data = <?= json_encode([
        'days' => $chart['days'],
        'ok' => $chart['req_ok'],
        'failed' => $chart['req_failed'],
        'activations' => $chart['activations'],
        'status' => $chart['status'],
    ]) ?>;

    const labels = data.days.map(function(d) {
        const p = d.split('-');
        return p[2] + '/' + p[1];
    });

    // Request API (batang bertumpuk) + aktivasi baru (garis)
    const reqEl = document.getElementById('dashRequestsChart');
    if (reqEl) {
        new Chart(reqEl, {
            data: {
                labels: labels,
                datasets: [
                    { type: 'bar', label: 'Sukses', data: data.ok, backgroundColor: C.brand, borderRadius: 4, maxBarThickness: 28, stack: 'req', order: 2 },
                    { type: 'bar', label: 'Gagal', data: data.failed, backgroundColor: C.err, borderRadius: 4, maxBarThickness: 28, stack: 'req', order: 2 },
                    { type: 'line', label: 'Aktivasi baru', data: data.activations, borderColor: C.lilac, backgroundColor: C.lilac, borderWidth: 2, pointRadius: 2, tension: 0.35, yAxisID: 'y', order: 1 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false } },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }

    // Status lisensi (donut)
    const statusEl = document.getElementById('dashStatusChart');
    if (statusEl) {
        const order = ['active', 'inactive', 'expired', 'suspended'];
        const colors = { active: C.brand, inactive: C.err, expired: C.warn, suspended: C.neutral };
        const values = order.map(function(k) { return data.status[k] || 0; });
        const empty = values.every(function(v) { return v === 0; });
        new Chart(statusEl, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Inactive', 'Expired', 'Suspended'],
                datasets: [{
                    data: empty ? [1] : values,
                    backgroundColor: empty ? [C.grid] : order.map(function(k) { return colors[k]; }),
                    borderColor: C.surface,
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: { legend: { display: false }, tooltip: { enabled: !empty } }
            }
        });
    }
})();
</script>

<?php include '../includes/footer.php'; ?>
