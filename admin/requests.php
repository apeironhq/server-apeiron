<?php
/**
 * API Requests — website mana saja yang mengirim request ke server.
 *
 * Halaman ini HANYA MEMBACA tabel api_logs yang sudah diisi oleh endpoint
 * API (activate, check, deactivate, validasi API key) lewat log_api_request().
 * Tidak ada data yang diubah.
 */

require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'API Requests';
$show_header = true;

// ---------------------------------------------------------------------------
// Helper tampilan
// ---------------------------------------------------------------------------

/** Ambil host dari URL situs: https://www.Contoh.id/path -> www.contoh.id */
function req_site_host($url): string {
    $url = trim((string) $url);
    if ($url === '') return '';
    $host = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'http://' . $url, PHP_URL_HOST);
    return strtolower($host ?: $url);
}

/** Samarkan nilai sensitif di data request/response untuk ditampilkan */
function req_mask_sensitive($data) {
    if (!is_array($data)) return $data;
    $sensitive = ['license_key', 'api_key', 'client_api_key', 'x-api-key', 'token', 'password', 'secret', 'signature', 'csrf_token'];
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $data[$k] = req_mask_sensitive($v);
        } elseif (is_string($v) && in_array(strtolower((string) $k), $sensitive, true) && $v !== '') {
            $len = strlen($v);
            $data[$k] = $len > 12 ? substr($v, 0, 6) . '…' . substr($v, -4) : str_repeat('•', min($len, 8));
        }
    }
    return $data;
}

/** Pesan singkat dari response_data (mis. "License key not found") */
function req_response_message($json): string {
    $data = json_decode((string) $json, true);
    if (!is_array($data)) return '';
    return (string) ($data['message'] ?? ($data['error_code'] ?? ''));
}

function req_status_badge(int $code): string {
    if ($code >= 500) return 'danger';
    if ($code >= 400) return 'warning';
    return 'success';
}

// ---------------------------------------------------------------------------
// Struktur tabel (dibaca saat runtime supaya aman terhadap versi skema)
// ---------------------------------------------------------------------------
$table_ready = false;
$has_created_at = false;
$load_error = '';

try {
    $db = get_db_connection();
    $columns = $db->query("SHOW COLUMNS FROM api_logs")->fetchAll(PDO::FETCH_COLUMN);
    $table_ready = !empty($columns);
    $has_created_at = in_array('created_at', $columns, true);
} catch (Exception $e) {
    $load_error = 'Tabel api_logs belum tersedia. Pastikan "Enable API Logging" aktif di Settings.';
}

// ---------------------------------------------------------------------------
// Popup detail satu request (?detail=ID&modal=1)
// ---------------------------------------------------------------------------
if (isset($_GET['detail'])) {
    $detail_id = intval($_GET['detail']);
    $row = null;
    if ($table_ready && $detail_id > 0) {
        try {
            $stmt = $db->prepare("SELECT * FROM api_logs WHERE id = ?");
            $stmt->execute([$detail_id]);
            $row = $stmt->fetch();
        } catch (Exception $e) {
            $row = null;
        }
    }

    if (!$row) {
        http_response_code(404);
        echo '<div data-modal-fragment><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span>Request tidak ditemukan.</span></div></div>';
        exit;
    }

    $req = req_mask_sensitive(json_decode((string) $row['request_data'], true));
    $res = req_mask_sensitive(json_decode((string) $row['response_data'], true));
    $code = (int) $row['status_code'];
    ?>
    <div data-modal-fragment>
        <div class="detail-grid">
            <div>
                <div class="section-title">Request</div>
                <div class="kv-list">
                    <div class="kv-row"><span>Endpoint</span><span><span class="badge badge-info"><?= htmlspecialchars($row['endpoint']) ?></span></span></div>
                    <div class="kv-row"><span>Method</span><span><?= htmlspecialchars($row['method'] ?? '-') ?></span></div>
                    <div class="kv-row"><span>Status</span><span><span class="badge badge-<?= req_status_badge($code) ?>"><?= $code ?></span></span></div>
                    <?php if ($has_created_at && !empty($row['created_at'])): ?>
                    <div class="kv-row"><span>Waktu</span><span><?= format_date($row['created_at']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <div class="section-title">Pengirim</div>
                <div class="kv-list">
                    <div class="kv-row"><span>Website</span><span><?= htmlspecialchars($row['site_url'] ?: '-') ?></span></div>
                    <div class="kv-row"><span>IP Address</span><span><?= htmlspecialchars($row['ip_address'] ?: '-') ?></span></div>
                    <div class="kv-row"><span>License</span><span><?= $row['license_key'] ? '<code>' . htmlspecialchars(ui_mask_key_fallback($row['license_key'])) . '</code>' : '-' ?></span></div>
                </div>
            </div>
        </div>

        <div class="detail-section">
            <div class="section-title">User Agent</div>
            <code class="code-block"><?= htmlspecialchars($row['user_agent'] ?: '-') ?></code>
        </div>

        <div class="detail-section">
            <div class="section-title">Request Data <span style="text-transform: none; letter-spacing: 0;">(nilai rahasia disamarkan)</span></div>
            <pre class="code-block" style="white-space: pre-wrap; margin: 0;"><?= htmlspecialchars(json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '-') ?></pre>
        </div>

        <div class="detail-section">
            <div class="section-title">Response Data</div>
            <pre class="code-block" style="white-space: pre-wrap; margin: 0;"><?= htmlspecialchars(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '-') ?></pre>
        </div>
    </div>
    <?php
    exit;
}

/** Penyamaran key untuk popup (header.php belum dimuat di mode popup) */
function ui_mask_key_fallback($key): string {
    $key = (string) $key;
    return strlen($key) > 20 ? substr($key, 0, 8) . '…' . substr($key, -8) : $key;
}

// ---------------------------------------------------------------------------
// Filter
// ---------------------------------------------------------------------------
$periods = ['1' => '24 jam terakhir', '7' => '7 hari terakhir', '30' => '30 hari terakhir', '90' => '90 hari terakhir'];
$period = (string) ($_GET['period'] ?? '7');
if (!isset($periods[$period])) $period = '7';

$filter_endpoint = (string) ($_GET['endpoint'] ?? '');
$filter_status = (string) ($_GET['status'] ?? '');
if (!in_array($filter_status, ['', 'success', 'failed'], true)) $filter_status = '';
$search = trim((string) ($_GET['search'] ?? ''));
$tab = ($_GET['tab'] ?? 'sites') === 'requests' ? 'requests' : 'sites';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;

$where = [];
$params = [];
if ($has_created_at) {
    $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
    $params[] = (int) $period;
}
if ($filter_endpoint !== '') {
    $where[] = 'endpoint = ?';
    $params[] = $filter_endpoint;
}
if ($filter_status === 'success') {
    $where[] = 'status_code < 400';
} elseif ($filter_status === 'failed') {
    $where[] = 'status_code >= 400';
}
if ($search !== '') {
    $where[] = '(site_url LIKE ? OR ip_address LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$stats = ['total' => 0, 'failed' => 0, 'last' => null];
$sites = [];
$requests = [];
$endpoints = [];
$total_rows = 0;

if ($table_ready) {
    try {
        $time_col = $has_created_at ? 'MAX(created_at)' : 'NULL';

        $stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(status_code >= 400) AS failed, {$time_col} AS last FROM api_logs {$where_sql}");
        $stmt->execute($params);
        $stats = $stmt->fetch() ?: $stats;

        $endpoints = $db->query("SELECT DISTINCT endpoint FROM api_logs ORDER BY endpoint")->fetchAll(PDO::FETCH_COLUMN);

        // Ringkasan per website. Dikelompokkan di PHP supaya variasi penulisan
        // URL (http/https, garis miring, path) tetap jadi satu website.
        $order = $has_created_at ? 'last_seen DESC' : 'requests DESC';
        $stmt = $db->prepare("
            SELECT site_url,
                   COUNT(*) AS requests,
                   SUM(status_code >= 400) AS failed,
                   GROUP_CONCAT(DISTINCT endpoint ORDER BY endpoint SEPARATOR ',') AS endpoints,
                   " . ($has_created_at ? 'MAX(created_at)' : 'MAX(id)') . " AS last_seen,
                   SUBSTRING_INDEX(GROUP_CONCAT(ip_address ORDER BY id DESC SEPARATOR '|'), '|', 1) AS last_ip
            FROM api_logs
            {$where_sql}
            GROUP BY site_url
            ORDER BY {$order}
            LIMIT 2000
        ");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $host = req_site_host($r['site_url']);
            $key = $host !== '' ? $host : '__none__';
            if (!isset($sites[$key])) {
                $sites[$key] = [
                    'host' => $host,
                    'sample_url' => $r['site_url'],
                    'requests' => 0,
                    'failed' => 0,
                    'endpoints' => [],
                    'last_seen' => $r['last_seen'],
                    'last_ip' => $r['last_ip'],
                ];
            }
            $s = &$sites[$key];
            $s['requests'] += (int) $r['requests'];
            $s['failed'] += (int) $r['failed'];
            foreach (array_filter(explode(',', (string) $r['endpoints'])) as $ep) {
                $s['endpoints'][$ep] = true;
            }
            if ((string) $r['last_seen'] > (string) $s['last_seen']) {
                $s['last_seen'] = $r['last_seen'];
                $s['last_ip'] = $r['last_ip'];
            }
            unset($s);
        }
        uasort($sites, function ($a, $b) {
            return strcmp((string) $b['last_seen'], (string) $a['last_seen']) ?: ($b['requests'] <=> $a['requests']);
        });

        // Riwayat request
        $stmt = $db->prepare("SELECT COUNT(*) FROM api_logs {$where_sql}");
        $stmt->execute($params);
        $total_rows = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * $per_page;
        $stmt = $db->prepare("
            SELECT id, endpoint, method, license_key, site_url, status_code, ip_address, user_agent, response_data"
            . ($has_created_at ? ', created_at' : '') . "
            FROM api_logs
            {$where_sql}
            ORDER BY id DESC
            LIMIT " . (int) $per_page . " OFFSET " . (int) $offset
        );
        $stmt->execute($params);
        $requests = $stmt->fetchAll();
    } catch (Exception $e) {
        $load_error = 'Gagal membaca api_logs: ' . $e->getMessage();
    }
}

$total_pages = max(1, (int) ceil($total_rows / $per_page));
$unique_sites = count(array_filter(array_keys($sites), function ($k) { return $k !== '__none__'; }));

/** URL halaman ini dengan filter saat ini, ditimpa oleh $overrides */
function req_url(array $overrides = []): string {
    global $period, $filter_endpoint, $filter_status, $search, $tab;
    $q = array_merge([
        'tab' => $tab,
        'period' => $period,
        'endpoint' => $filter_endpoint,
        'status' => $filter_status,
        'search' => $search,
    ], $overrides);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return 'requests.php' . ($q ? '?' . http_build_query($q) : '');
}

include '../includes/header.php';
?>

<?php if ($load_error): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <span><?= htmlspecialchars($load_error) ?></span>
    </div>
<?php endif; ?>

<?php if ($table_ready && !get_setting('enable_api_logging', true)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <span>API Logging sedang nonaktif, jadi request baru tidak tercatat. Aktifkan di <a href="settings.php">Settings</a>.</span>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card info">
        <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
        <div class="stat-label">Total Requests</div>
        <div class="stat-value"><?= number_format((int) ($stats['total'] ?? 0)) ?></div>
        <div class="stat-change"><i class="fas fa-clock"></i> <?= htmlspecialchars($has_created_at ? $periods[$period] : 'Semua waktu') ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-globe"></i></div>
        <div class="stat-label">Websites</div>
        <div class="stat-value"><?= number_format($unique_sites) ?></div>
        <div class="stat-change"><i class="fas fa-link"></i> Website unik</div>
    </div>
    <div class="stat-card <?= (int) ($stats['failed'] ?? 0) > 0 ? 'danger' : 'success' ?>">
        <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
        <div class="stat-label">Failed</div>
        <div class="stat-value"><?= number_format((int) ($stats['failed'] ?? 0)) ?></div>
        <div class="stat-change"><i class="fas fa-exclamation-circle"></i> Status 4xx / 5xx</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-history"></i></div>
        <div class="stat-label">Last Request</div>
        <div class="stat-value" style="font-size: var(--fs-h2);"><?= !empty($stats['last']) ? time_ago($stats['last']) : '-' ?></div>
        <div class="stat-change"><i class="fas fa-calendar"></i> <?= !empty($stats['last']) ? format_date($stats['last']) : 'Belum ada' ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-satellite-dish"></i> API Requests
        </h1>
        <div class="page-tabs">
            <a href="<?= htmlspecialchars(req_url(['tab' => 'sites', 'page' => null])) ?>" class="page-tab <?= $tab === 'sites' ? 'active' : '' ?>">
                <i class="fas fa-globe"></i> Websites
            </a>
            <a href="<?= htmlspecialchars(req_url(['tab' => 'requests', 'page' => null])) ?>" class="page-tab <?= $tab === 'requests' ? 'active' : '' ?>">
                <i class="fas fa-list"></i> Requests
            </a>
        </div>
    </div>

    <form method="GET" class="toolbar">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="toolbar-search">
            <input type="text" name="search" class="form-control" placeholder="Cari website atau IP..."
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-search"></i> Search
            </button>
        </div>
        <?php if ($has_created_at): ?>
        <select name="period" class="form-control" onchange="this.form.submit()">
            <?php foreach ($periods as $value => $label): ?>
                <option value="<?= $value ?>" <?= $period === (string) $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="endpoint" class="form-control" onchange="this.form.submit()">
            <option value="">All Endpoints</option>
            <?php foreach ($endpoints as $ep): ?>
                <option value="<?= htmlspecialchars($ep) ?>" <?= $filter_endpoint === $ep ? 'selected' : '' ?>><?= htmlspecialchars($ep) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-control" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="success" <?= $filter_status === 'success' ? 'selected' : '' ?>>Success</option>
            <option value="failed" <?= $filter_status === 'failed' ? 'selected' : '' ?>>Failed</option>
        </select>
    </form>

    <?php if ($tab === 'sites'): ?>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Website</th>
                    <th>Requests</th>
                    <th>Success</th>
                    <th>Failed</th>
                    <th>Endpoints</th>
                    <th>Last Seen / IP</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sites)): ?>
                    <tr class="empty-row"><td colspan="7">Belum ada request pada filter ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($sites as $key => $site): ?>
                    <?php $failed = (int) $site['failed']; $ok = (int) $site['requests'] - $failed; ?>
                    <tr>
                        <td>
                            <?php if ($key === '__none__'): ?>
                                <span class="cell-muted">(tanpa website)</span>
                                <span class="cell-sub">mis. validasi API key / portal</span>
                            <?php else: ?>
                                <span class="key-chip key-chip-wide" style="font-weight: var(--fw-medium); color: var(--text-heading);" title="<?= htmlspecialchars($site['sample_url']) ?>"><?= htmlspecialchars($site['host']) ?></span>
                                <span class="cell-sub key-chip key-chip-wide" style="display: block;"><?= htmlspecialchars($site['sample_url']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><strong style="color: var(--text-heading);"><?= number_format((int) $site['requests']) ?></strong></td>
                        <td><span class="badge badge-success"><?= number_format($ok) ?></span></td>
                        <td>
                            <?php if ($failed > 0): ?>
                                <span class="badge badge-danger"><?= number_format($failed) ?></span>
                            <?php else: ?>
                                <span class="cell-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach (array_keys($site['endpoints']) as $ep): ?>
                                <span class="badge badge-info"><?= htmlspecialchars($ep) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if ($has_created_at && $site['last_seen']): ?>
                                <?= format_date($site['last_seen']) ?>
                                <span class="cell-muted"> · <?= time_ago($site['last_seen']) ?></span>
                            <?php else: ?>
                                <span class="cell-muted">-</span>
                            <?php endif; ?>
                            <span class="cell-sub key-chip" style="display: block; max-width: 220px;" title="<?= htmlspecialchars($site['last_ip'] ?: '') ?>"><?= htmlspecialchars($site['last_ip'] ?: '-') ?></span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <?php if ($key !== '__none__'): ?>
                                <a href="<?= htmlspecialchars(req_url(['tab' => 'requests', 'search' => $site['host'], 'page' => null])) ?>"
                                   class="btn btn-sm btn-secondary btn-icon" title="Lihat request dari website ini">
                                    <i class="fas fa-list"></i>
                                </a>
                                <a href="<?= htmlspecialchars(preg_match('#^https?://#i', (string) $site['sample_url']) ? $site['sample_url'] : 'https://' . $site['host']) ?>"
                                   target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-secondary btn-icon" title="Buka website">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Website</th>
                    <th>Endpoint</th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr class="empty-row"><td colspan="6">Belum ada request pada filter ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                    <?php $code = (int) $r['status_code']; $msg = req_response_message($r['response_data']); ?>
                    <tr>
                        <td>
                            <?php if ($has_created_at): ?>
                                <?= format_date($r['created_at']) ?>
                                <span class="cell-sub"><?= time_ago($r['created_at']) ?></span>
                            <?php else: ?>
                                <span class="cell-muted">#<?= (int) $r['id'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['site_url']): ?>
                                <code class="key-chip key-chip-wide" title="<?= htmlspecialchars($r['site_url']) ?>"><?= htmlspecialchars($r['site_url']) ?></code>
                            <?php else: ?>
                                <span class="cell-muted">(tanpa website)</span>
                            <?php endif; ?>
                            <span class="cell-sub"><?= $r['license_key'] ? htmlspecialchars(ui_mask_key($r['license_key'])) : '—' ?></span>
                        </td>
                        <td><span class="badge badge-info"><?= htmlspecialchars($r['endpoint']) ?></span></td>
                        <td><span class="key-chip" style="max-width: 160px;" title="<?= htmlspecialchars($r['ip_address'] ?: '') ?>"><?= htmlspecialchars($r['ip_address'] ?: '-') ?></span></td>
                        <td>
                            <span class="badge badge-<?= req_status_badge($code) ?>"><?= $code ?></span>
                            <?php if ($msg !== ''): ?>
                                <span class="cell-sub key-chip" style="display: block; max-width: 200px;" title="<?= htmlspecialchars($msg) ?>"><?= htmlspecialchars($msg) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn btn-sm btn-secondary btn-icon" title="Detail request"
                                        data-modal-url="requests.php?detail=<?= (int) $r['id'] ?>&amp;modal=1"
                                        data-modal-title="Request #<?= (int) $r['id'] ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="toolbar" style="justify-content: space-between; margin: 16px 0 0;">
        <span class="cell-muted" style="font-size: var(--fs-caption);">
            Halaman <?= $page ?> dari <?= $total_pages ?> · <?= number_format($total_rows) ?> request
        </span>
        <div class="row-actions">
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars(req_url(['page' => $page - 1])) ?>"><i class="fas fa-chevron-left"></i> Prev</a>
            <?php endif; ?>
            <?php if ($page < $total_pages): ?>
                <a class="btn btn-sm btn-secondary" href="<?= htmlspecialchars(req_url(['page' => $page + 1])) ?>">Next <i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
