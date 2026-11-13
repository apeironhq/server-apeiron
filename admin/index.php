<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Dashboard';
$show_header = true;

// Get statistics
$stats = get_license_stats();
$recent_activity = get_recent_activity(10);

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

include '../includes/header.php';

// ---------------------------------------------------------------------------
// Helper tampilan dashboard
// ---------------------------------------------------------------------------
$__days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$__months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$__today = $__days[(int) date('w')] . ', ' . date('j') . ' ' . $__months[(int) date('n')] . ' ' . date('Y');
$__admin = $_SESSION['admin_username'] ?? 'Admin';

/** Samarkan license key yang tertulis di dalam teks deskripsi log */
$__mask_in_text = function ($text) {
    return preg_replace_callback('/\bAPEIRON-[A-Z0-9-]{12,}/i', function ($m) {
        return ui_mask_key($m[0]);
    }, (string) $text);
};

$__action_icon = function ($action) {
    $map = [
        'create' => 'plus-circle', 'update' => 'edit', 'delete' => 'trash', 'bulk_delete' => 'trash',
        'login' => 'sign-in-alt', 'logout' => 'sign-out-alt', 'suspend' => 'ban', 'activate' => 'check',
        'remote_deactivate' => 'power-off', 'bulk_update' => 'sync',
    ];
    return $map[$action] ?? 'circle';
};

$__action_tone = function ($action) {
    if (in_array($action, ['delete', 'bulk_delete', 'remote_deactivate'], true)) return 'danger';
    if (in_array($action, ['suspend'], true)) return 'warning';
    if (in_array($action, ['create', 'activate'], true)) return 'success';
    return 'info';
};

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
    <div class="dash-hero-actions">
        <a href="requests.php" class="btn btn-secondary">
            <i class="fas fa-satellite-dish"></i> API Requests
        </a>
        <a href="licenses.php?generate=1" class="btn btn-primary">
            <i class="fas fa-plus"></i> Generate License
        </a>
    </div>
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

<div class="dashboard-grid dash-grid">
    <div class="card dash-card">
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
                        <th>Status</th>
                        <th>Activations</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_licenses)): ?>
                        <tr class="empty-row"><td colspan="4">Belum ada lisensi.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_licenses as $license): ?>
                        <tr>
                            <td>
                                <?php // SECURITY: key disamarkan di tampilan ?>
                                <code class="key-chip"><?= htmlspecialchars(ui_mask_key($license['license_key'])) ?></code>
                                <span class="cell-sub key-chip" style="display: block; max-width: 220px;">
                                    <?= htmlspecialchars($license['customer_name'] ?: $license['product_id']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars($license['status']) ?>">
                                    <?= ucfirst(htmlspecialchars($license['status'])) ?>
                                </span>
                            </td>
                            <td><?= (int) $license['activation_count'] ?> / <?= $license['activation_limit'] ?: '∞' ?></td>
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

    <div class="card dash-card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-hourglass-half"></i> Expiring Soon
            </h2>
            <span class="badge badge-warning">30 hari</span>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>License</th>
                        <th>Expires</th>
                        <th>Days Left</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expiring_soon)): ?>
                        <tr class="empty-row"><td colspan="3">Tidak ada lisensi yang akan habis dalam 30 hari.</td></tr>
                    <?php else: ?>
                        <?php foreach ($expiring_soon as $license): ?>
                        <?php
                            $days_left = (strtotime($license['expires']) - time()) / 86400;
                            $status_class = $days_left <= 7 ? 'danger' : 'warning';
                        ?>
                        <tr>
                            <td>
                                <code class="key-chip"><?= htmlspecialchars(ui_mask_key($license['license_key'])) ?></code>
                                <span class="cell-sub key-chip" style="display: block; max-width: 220px;">
                                    <?= htmlspecialchars($license['customer_name'] ?: $license['product_id']) ?>
                                </span>
                            </td>
                            <td><?= format_date($license['expires'], 'Y-m-d') ?></td>
                            <td>
                                <span class="badge badge-<?= $status_class ?>">
                                    <?= max(0, (int) floor($days_left)) ?> days
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-history"></i> Recent Activity
        </h2>
        <a href="logs.php" class="btn btn-sm btn-secondary">View All Logs</a>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Description</th>
                    <th>User</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_activity)): ?>
                    <tr class="empty-row"><td colspan="4">Belum ada aktivitas.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent_activity as $activity): ?>
                    <?php
                        $desc = $__mask_in_text($activity['description'] ?: '-');
                        $act = (string) $activity['action'];
                    ?>
                    <tr>
                        <td>
                            <span class="badge badge-<?= $__action_tone($act) ?>">
                                <i class="fas fa-<?= $__action_icon($act) ?>"></i>
                                <?= htmlspecialchars($act) ?>
                            </span>
                        </td>
                        <td>
                            <span class="key-chip" style="max-width: 560px;" title="<?= htmlspecialchars($desc) ?>"><?= htmlspecialchars($desc) ?></span>
                        </td>
                        <td><?= htmlspecialchars($activity['username'] ?: 'System') ?></td>
                        <td>
                            <?= time_ago($activity['created_at']) ?>
                            <span class="cell-sub"><?= format_date($activity['created_at'], 'Y-m-d H:i') ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
