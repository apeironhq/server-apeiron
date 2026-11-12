<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'License Details';
$show_header = true;

$license_id = intval($_GET['id'] ?? 0);

if (!$license_id) {
    header('Location: licenses.php');
    exit;
}

// Detail kini tampil sebagai popup di licenses.php. Alamat lama diarahkan ke sana;
// ?modal=1 mengembalikan potongan HTML untuk isi popup.
$is_modal = isset($_GET['modal']);
if (!$is_modal) {
    header('Location: licenses.php?view=' . $license_id);
    exit;
}

function license_detail_error(string $message): void {
    http_response_code(404);
    echo '<div data-modal-fragment><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span>'
        . htmlspecialchars($message) . '</span></div></div>';
    exit;
}

try {
    $db = get_db_connection();
    
    // Get license details
    $stmt = $db->prepare("
        SELECT l.*, ad.username as created_by_name, ad.full_name as created_by_fullname
        FROM licenses l
        LEFT JOIN admins ad ON l.created_by = ad.id
        WHERE l.id = ?
    ");
    $stmt->execute([$license_id]);
    $license = $stmt->fetch();
    
    if (!$license) {
        license_detail_error('License not found');
    }
    
    // Get activations
    $activations = $db->prepare("
        SELECT * FROM activations 
        WHERE license_id = ? 
        ORDER BY activated_at DESC
    ");
    $activations->execute([$license_id]);
    $activations = $activations->fetchAll();
    
    // Get activity logs for this license
    $logs = $db->prepare("
        SELECT al.*, ad.username
        FROM activity_logs al
        LEFT JOIN admins ad ON al.admin_id = ad.id
        WHERE al.entity_type = 'license' AND al.entity_id = ?
        ORDER BY al.created_at DESC
        LIMIT 50
    ");
    $logs->execute([$license_id]);
    $logs = $logs->fetchAll();
    
} catch (Exception $e) {
    license_detail_error('Error: ' . $e->getMessage());
}

$edit_payload = [
    'id' => $license['id'],
    'status' => $license['status'],
    'expires' => $license['expires'],
    'activation_limit' => $license['activation_limit'],
    'customer_name' => $license['customer_name'],
    'customer_email' => $license['customer_email'],
    'notes' => $license['notes'],
];
?>
<div data-modal-fragment>
    <div class="detail-actions">
        <button type="button" class="btn btn-sm btn-secondary" data-copy="<?= htmlspecialchars($license['license_key']) ?>">
            <i class="fas fa-copy"></i> Copy Key
        </button>
        <button type="button" class="btn btn-sm btn-primary" data-edit-license="<?= htmlspecialchars(json_encode($edit_payload)) ?>">
            <i class="fas fa-edit"></i> Edit
        </button>
    </div>

    <div class="detail-key">
        <i class="fas fa-key" style="color: var(--text-faint);"></i>
        <code><?= htmlspecialchars($license['license_key']) ?></code>
    </div>

    <div class="detail-grid">
        <div>
            <div class="section-title">License Information</div>
            <div class="kv-list">
                <div class="kv-row"><span>Product ID</span><span><?= htmlspecialchars($license['product_id']) ?></span></div>
                <div class="kv-row">
                    <span>Status</span>
                    <span><span class="badge badge-<?= htmlspecialchars($license['status']) ?>"><?= ucfirst(htmlspecialchars($license['status'])) ?></span></span>
                </div>
                <div class="kv-row">
                    <span>Expires</span>
                    <span>
                        <?php if ($license['expires']): ?>
                            <?php
                                $days_left = (strtotime($license['expires']) - time()) / 86400;
                                $status_class = $days_left < 0 ? 'danger' : ($days_left <= 7 ? 'warning' : 'success');
                            ?>
                            <?= format_date($license['expires'], 'Y-m-d') ?>
                            <span class="badge badge-<?= $status_class ?>" style="margin-left: 6px;"><?= floor($days_left) ?> days left</span>
                        <?php else: ?>
                            Never
                        <?php endif; ?>
                    </span>
                </div>
                <div class="kv-row"><span>Activation Limit</span><span><?= $license['activation_limit'] ?: 'Unlimited' ?></span></div>
                <div class="kv-row">
                    <span>Created</span>
                    <span>
                        <?= format_date($license['created_at']) ?>
                        <?php if ($license['created_by_name']): ?>
                            <span class="cell-sub">by <?= htmlspecialchars($license['created_by_fullname'] ?: $license['created_by_name']) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <div>
            <div class="section-title">Customer Information</div>
            <div class="kv-list">
                <div class="kv-row"><span>Name</span><span><?= htmlspecialchars($license['customer_name'] ?: '-') ?></span></div>
                <div class="kv-row"><span>Email</span><span><?= htmlspecialchars($license['customer_email'] ?: '-') ?></span></div>
                <div class="kv-row"><span>Notes</span><span><?= nl2br(htmlspecialchars($license['notes'] ?: '-')) ?></span></div>
            </div>
        </div>
    </div>

    <div class="detail-section">
        <div class="section-title"><i class="fas fa-link"></i> Activations (<?= count($activations) ?>)</div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Site URL</th>
                        <th>Site Name</th>
                        <th>IP Address</th>
                        <th>Status</th>
                        <th>Activated</th>
                        <th>Last Check</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activations)): ?>
                        <tr class="empty-row"><td colspan="6">No activations yet</td></tr>
                    <?php else: ?>
                        <?php foreach ($activations as $activation): ?>
                        <tr>
                            <td><code class="key-chip key-chip-wide" title="<?= htmlspecialchars($activation['site_url']) ?>"><?= htmlspecialchars($activation['site_url']) ?></code></td>
                            <td><?= htmlspecialchars($activation['site_name'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($activation['ip_address'] ?: '-') ?></td>
                            <td>
                                <span class="badge badge-<?= $activation['status'] === 'active' ? 'success' : 'inactive' ?>">
                                    <?= ucfirst(htmlspecialchars($activation['status'])) ?>
                                </span>
                            </td>
                            <td><?= format_date($activation['activated_at']) ?></td>
                            <td><?= $activation['last_check'] ? format_date($activation['last_check']) : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="detail-section">
        <div class="section-title"><i class="fas fa-history"></i> Activity Logs</div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Description</th>
                        <th>User</th>
                        <th>IP Address</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr class="empty-row"><td colspan="5">No activity logs</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><span class="badge badge-info"><?= htmlspecialchars($log['action']) ?></span></td>
                            <td><?= htmlspecialchars($log['description'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($log['username'] ?: 'System') ?></td>
                            <td><?= htmlspecialchars($log['ip_address'] ?: '-') ?></td>
                            <td><?= format_date($log['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

