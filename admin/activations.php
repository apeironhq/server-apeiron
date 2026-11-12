<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Activations';
$show_header = true;

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

try {
    $db = get_db_connection();
    
    $where = [];
    $params = [];
    
    if ($filter_status) {
        $where[] = "a.status = ?";
        $params[] = $filter_status;
    }
    
    if ($search) {
        $where[] = "(a.site_url LIKE ? OR l.license_key LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $activations = $db->prepare("
        SELECT a.*, l.license_key, l.product_id, l.status as license_status
        FROM activations a
        LEFT JOIN licenses l ON a.license_id = l.id
        {$where_sql}
        ORDER BY a.activated_at DESC
    ");
    $activations->execute($params);
    $activations = $activations->fetchAll();
    
} catch (Exception $e) {
    $activations = [];
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-link"></i> Activations (<?= count($activations) ?>)
        </h1>
    </div>
    
    <div class="toolbar">
        <form method="GET" class="toolbar-search">
            <input type="text" name="search" class="form-control" placeholder="Search site URL or license key..."
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-search"></i> Search
            </button>
        </form>

        <select class="form-control"
                onchange="window.location.href='?status='+encodeURIComponent(this.value)+'&search=<?= htmlspecialchars(urlencode($search), ENT_QUOTES) ?>'">
            <option value="">All Status</option>
            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="deactivated" <?= $filter_status === 'deactivated' ? 'selected' : '' ?>>Deactivated</option>
        </select>
    </div>
    
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>License Key</th>
                    <th>Site URL</th>
                    <th>Site Name</th>
                    <th>IP Address</th>
                    <th>License Status</th>
                    <th>Activation Status</th>
                    <th>Activated</th>
                    <th>Last Check</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activations)): ?>
                    <tr class="empty-row">
                        <td colspan="9">No activations found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($activations as $activation): ?>
                    <tr>
                        <td>
                            <code class="key-chip"><?= htmlspecialchars(ui_mask_key($activation['license_key'])) ?></code>
                            <span class="cell-sub"><?= htmlspecialchars($activation['product_id']) ?></span>
                        </td>
                        <td>
                            <code class="key-chip key-chip-wide" title="<?= htmlspecialchars($activation['site_url']) ?>"><?= htmlspecialchars($activation['site_url']) ?></code>
                        </td>
                        <td><?= htmlspecialchars($activation['site_name'] ?: '-') ?></td>
                        <td><span class="key-chip" title="<?= htmlspecialchars($activation['ip_address'] ?: '') ?>" style="max-width: 160px;"><?= htmlspecialchars($activation['ip_address'] ?: '-') ?></span></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars((string) $activation['license_status']) ?>">
                                <?= ucfirst(htmlspecialchars((string) $activation['license_status'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?= $activation['status'] === 'active' ? 'success' : 'inactive' ?>">
                                <?= ucfirst(htmlspecialchars($activation['status'])) ?>
                            </span>
                        </td>
                        <td><?= format_date($activation['activated_at']) ?></td>
                        <td><?= $activation['last_check'] ? format_date($activation['last_check']) : '-' ?></td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn btn-sm btn-secondary btn-icon" title="Copy license key"
                                        data-copy="<?= htmlspecialchars((string) $activation['license_key']) ?>">
                                    <i class="fas fa-copy"></i>
                                </button>
                                <?php if (!empty($activation['license_id'])): ?>
                                <button type="button" class="btn btn-sm btn-secondary btn-icon" title="License detail"
                                        data-modal-url="license-details.php?id=<?= (int) $activation['license_id'] ?>&amp;modal=1"
                                        data-modal-title="License Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

