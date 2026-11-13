<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/pagination.php';

require_admin_login();

$page_title = 'Manage Licenses';
$show_header = true;

$message = '';

// FEATURE: Export functionality
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_csrf_token(); // Validate via GET token or session
    
    try {
        $db = get_db_connection();
        
        // Get all licenses (or filtered)
        $filter_status = $_GET['status'] ?? '';
        $filter_product = $_GET['product'] ?? '';
        $search = $_GET['search'] ?? '';
        
        $where = [];
        $params = [];
        
        if ($filter_status) {
            $where[] = "l.status = ?";
            $params[] = $filter_status;
        }
        
        if ($filter_product) {
            $where[] = "l.product_id = ?";
            $params[] = $filter_product;
        }
        
        if ($search) {
            $where[] = "(l.license_key LIKE ? OR l.customer_name LIKE ? OR l.customer_email LIKE ?)";
            $search_term = "%{$search}%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $stmt = $db->prepare("
            SELECT l.*, COUNT(DISTINCT a.id) as activation_count,
                   ad.username as created_by_name
            FROM licenses l 
            LEFT JOIN activations a ON l.id = a.license_id AND a.status = 'active'
            LEFT JOIN admins ad ON l.created_by = ad.id
            {$where_sql}
            GROUP BY l.id 
            ORDER BY l.created_at DESC
        ");
        $stmt->execute($params);
        $licenses = $stmt->fetchAll();
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="licenses_' . date('Y-m-d_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output CSV
        $output = fopen('php://output', 'w');
        
        // BOM for UTF-8 Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, [
            'License Key',
            'Product ID',
            'Status',
            'Customer Name',
            'Customer Email',
            'Expires',
            'Activation Limit',
            'Activations',
            'Created At',
            'Created By',
            'Notes'
        ]);
        
        // Data rows
        foreach ($licenses as $license) {
            fputcsv($output, [
                $license['license_key'],
                $license['product_id'],
                $license['status'],
                $license['customer_name'] ?? '',
                $license['customer_email'] ?? '',
                $license['expires'] ?? '',
                $license['activation_limit'],
                $license['activation_count'],
                $license['created_at'],
                $license['created_by_name'] ?? '',
                $license['notes'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Export error: ' . $e->getMessage();
        header('Location: licenses.php');
        exit;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // SECURITY: Require CSRF token for all POST requests
    require_csrf_token();
    
    $action = $_POST['action'] ?? '';
    $license_id = intval($_POST['license_id'] ?? 0);
    
    try {
        $db = get_db_connection();
        
        // FEATURE: Bulk operations
        if ($action === 'bulk_delete' || $action === 'bulk_update_status') {
            $selected_ids = $_POST['selected_ids'] ?? [];
            if (empty($selected_ids) || !is_array($selected_ids)) {
                $_SESSION['error_message'] = 'Tidak ada license yang dipilih!';
                header('Location: licenses.php');
                exit;
            }
            
            // Validate all IDs are integers
            $selected_ids = array_map('intval', $selected_ids);
            $selected_ids = array_filter($selected_ids, function($id) { return $id > 0; });
            
            if (empty($selected_ids)) {
                $_SESSION['error_message'] = 'ID license tidak valid!';
                header('Location: licenses.php');
                exit;
            }
            
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            if ($action === 'bulk_delete') {
                // Get license keys for logging
                $stmt = $db->prepare("SELECT license_key FROM licenses WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $deleted_licenses = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete licenses
                $stmt = $db->prepare("DELETE FROM licenses WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                
                log_activity('bulk_delete', 'license', null, "Deleted " . count($selected_ids) . " license(s)");
                $_SESSION['success_message'] = count($selected_ids) . ' license berhasil dihapus!';
            } elseif ($action === 'bulk_update_status') {
                $new_status = sanitize_input($_POST['new_status'] ?? '');
                if (!validate_status($new_status)) {
                    $_SESSION['error_message'] = 'Status tidak valid!';
                    header('Location: licenses.php');
                    exit;
                }
                
                $stmt = $db->prepare("UPDATE licenses SET status = ? WHERE id IN ($placeholders)");
                $stmt->execute(array_merge([$new_status], $selected_ids));
                
                log_activity('bulk_update', 'license', null, "Updated status to {$new_status} for " . count($selected_ids) . " license(s)");
                $_SESSION['success_message'] = count($selected_ids) . ' license berhasil diupdate status ke ' . ucfirst($new_status) . '!';
            }
            
            header('Location: licenses.php');
            exit;
        }
        
        if ($action === 'delete') {
            $stmt = $db->prepare("SELECT license_key FROM licenses WHERE id = ?");
            $stmt->execute([$license_id]);
            $license = $stmt->fetch();
            
            if ($license) {
                $db->prepare("DELETE FROM licenses WHERE id = ?")->execute([$license_id]);
                log_activity('delete', 'license', $license_id, "Deleted license: {$license['license_key']}");
                $_SESSION['success_message'] = 'License berhasil dihapus!';
            }
        } elseif ($action === 'update') {
            require_once __DIR__ . '/../includes/validation.php';
            
            // FIX: Validate all inputs properly
            $status = sanitize_input($_POST['status'] ?? 'inactive');
            if (!validate_status($status)) {
                $_SESSION['error_message'] = 'Invalid status value';
                header('Location: licenses.php');
                exit;
            }
            
            $expires = !empty($_POST['expires']) ? $_POST['expires'] : null;
            if ($expires && !validate_date($expires, 'Y-m-d')) {
                $_SESSION['error_message'] = 'Invalid expiration date format';
                header('Location: licenses.php');
                exit;
            }
            
            $activation_limit = validate_activation_limit($_POST['activation_limit'] ?? 5);
            $customer_name = sanitize_input($_POST['customer_name'] ?? '');
            $customer_name = validate_site_name($customer_name, 255) ?: '';
            
            $customer_email = sanitize_input($_POST['customer_email'] ?? '');
            if (!empty($customer_email)) {
                $validated_email = validate_and_sanitize_email($customer_email);
                if ($validated_email === false) {
                    $_SESSION['error_message'] = 'Invalid email format';
                    header('Location: licenses.php');
                    exit;
                }
                $customer_email = $validated_email;
            }
            
            $notes = sanitize_input($_POST['notes'] ?? '');
            
            $stmt = $db->prepare("
                UPDATE licenses 
                SET status = ?, expires = ?, activation_limit = ?, 
                    customer_name = ?, customer_email = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $expires, $activation_limit, $customer_name, $customer_email, $notes, $license_id]);
            
            log_activity('update', 'license', $license_id, "Updated license #{$license_id}");
            $_SESSION['success_message'] = 'License berhasil diupdate!';
        } elseif ($action === 'suspend') {
            $db->prepare("UPDATE licenses SET status = 'suspended' WHERE id = ?")->execute([$license_id]);
            log_activity('suspend', 'license', $license_id, "Suspended license #{$license_id}");
            $_SESSION['success_message'] = 'License berhasil di-suspend!';
        } elseif ($action === 'activate') {
            $db->prepare("UPDATE licenses SET status = 'active' WHERE id = ?")->execute([$license_id]);
            log_activity('activate', 'license', $license_id, "Activated license #{$license_id}");
            $_SESSION['success_message'] = 'License berhasil diaktifkan!';
        }
        
        header('Location: licenses.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_product = $_GET['product'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
try {
    $db = get_db_connection();
    
    $where = [];
    $params = [];
    
    if ($filter_status) {
        $where[] = "l.status = ?";
        $params[] = $filter_status;
    }
    
    if ($filter_product) {
        $where[] = "l.product_id = ?";
        $params[] = $filter_product;
    }
    
    if ($search) {
        $where[] = "(l.license_key LIKE ? OR l.customer_name LIKE ? OR l.customer_email LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get pagination parameters (already validated in get_pagination_params)
    $pagination = get_pagination_params();
    $page = $pagination['page'];
    $per_page = $pagination['per_page'];
    $offset = $pagination['offset'];
    
    // Ensure integers for SQL safety
    $per_page = (int)$per_page;
    $offset = (int)$offset;
    
    // Get total count
    $count_sql = "
        SELECT COUNT(DISTINCT l.id)
        FROM licenses l 
        LEFT JOIN activations a ON l.id = a.license_id AND a.status = 'active'
        LEFT JOIN admins ad ON l.created_by = ad.id
        {$where_sql}
    ";
    $count_stmt = $db->prepare($count_sql);
    $count_stmt->execute($params);
    $total_licenses = $count_stmt->fetchColumn();
    $total_pages = ceil($total_licenses / $per_page);
    
    // Get paginated licenses
    $licenses = $db->prepare("
        SELECT l.*, COUNT(DISTINCT a.id) as activation_count,
               ad.username as created_by_name
        FROM licenses l 
        LEFT JOIN activations a ON l.id = a.license_id AND a.status = 'active'
        LEFT JOIN admins ad ON l.created_by = ad.id
        {$where_sql}
        GROUP BY l.id 
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $per_page;
    $params[] = $offset;
    $licenses->execute($params);
    $licenses = $licenses->fetchAll();
    
    // Get products for filter
    $products = $db->query("SELECT DISTINCT product_id FROM licenses ORDER BY product_id")->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    $licenses = [];
    $products = [];
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

// Hasil generate (ditampilkan sekali sebagai popup) dan popup yang dibuka otomatis
$generated_keys = $_SESSION['generated_keys'] ?? [];
unset($_SESSION['generated_keys']);
$open_generate = isset($_GET['generate']);
$open_view_id = intval($_GET['view'] ?? 0);
$generate_products = !empty($products) ? $products : ['apeiron-kit'];

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-certificate"></i> Manage Licenses
        </h1>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn btn-primary" onclick="showModal('generateModal')">
                <i class="fas fa-plus"></i> Generate New
            </button>
            <a href="licenses.php?export=csv<?= !empty($filter_status) ? '&status=' . urlencode($filter_status) : '' ?><?= !empty($filter_product) ? '&product=' . urlencode($filter_product) : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn btn-secondary" id="exportBtn">
                <i class="fas fa-download"></i> Export CSV
            </a>
        </div>
    </div>

    <!-- FEATURE: Bulk Operations Bar -->
    <div id="bulkActionsBar" class="bulk-bar">
        <span id="selectedCount" class="bulk-bar-count">0 selected</span>
        <select id="bulkStatusSelect" class="form-control form-control-sm">
            <option value="">Select Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="expired">Expired</option>
            <option value="suspended">Suspended</option>
        </select>
        <form method="POST" id="bulkStatusForm"
              data-confirm="Status semua license yang dipilih akan diubah."
              data-confirm-title="Update status license?" data-confirm-ok="Ya, update" data-confirm-variant="primary">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="bulk_update_status">
            <input type="hidden" name="new_status" id="bulkNewStatus">
            <span class="bulk-ids"></span>
            <button type="submit" class="btn btn-sm btn-primary" id="bulkUpdateBtn" disabled>
                <i class="fas fa-sync"></i> Update Status
            </button>
        </form>
        <form method="POST" id="bulkDeleteForm"
              data-confirm="Semua license yang dipilih akan dihapus permanen. Tindakan ini tidak dapat dibatalkan."
              data-confirm-title="Hapus license terpilih?" data-confirm-ok="Ya, hapus">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="bulk_delete">
            <span class="bulk-ids"></span>
            <button type="submit" class="btn btn-sm btn-danger" id="bulkDeleteBtn" disabled>
                <i class="fas fa-trash"></i> Delete Selected
            </button>
        </form>
        <button type="button" class="btn btn-sm btn-secondary" onclick="clearSelection()">
            <i class="fas fa-times"></i> Clear
        </button>
    </div>

    <div class="toolbar">
        <form method="GET" class="toolbar-search">
            <input type="text" name="search" class="form-control" placeholder="Search license key, customer..."
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-search"></i> Search
            </button>
        </form>

        <select class="form-control" onchange="window.location.href='?status='+encodeURIComponent(this.value)+'&product=<?= htmlspecialchars(urlencode($filter_product), ENT_QUOTES) ?>&search=<?= htmlspecialchars(urlencode($search), ENT_QUOTES) ?>'">
            <option value="">All Status</option>
            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="expired" <?= $filter_status === 'expired' ? 'selected' : '' ?>>Expired</option>
            <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
        </select>

        <select class="form-control" onchange="window.location.href='?status=<?= htmlspecialchars(urlencode($filter_status), ENT_QUOTES) ?>&product='+encodeURIComponent(this.value)+'&search=<?= htmlspecialchars(urlencode($search), ENT_QUOTES) ?>'">
            <option value="">All Products</option>
            <?php foreach ($products as $product): ?>
                <option value="<?= htmlspecialchars($product) ?>" <?= $filter_product === $product ? 'selected' : '' ?>>
                    <?= htmlspecialchars($product) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <th>License Key</th>
                    <th>Status</th>
                    <th>Customer</th>
                    <th>Expires</th>
                    <th>Activations</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($licenses)): ?>
                    <tr class="empty-row">
                        <td colspan="8">No licenses found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($licenses as $license): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="license-checkbox" value="<?= $license['id'] ?>" onchange="updateBulkActions()">
                        </td>
                        <td>
                            <?php // SECURITY: key disamarkan di tabel; key lengkap ada di popup detail & tombol copy ?>
                            <code class="key-chip"><?= htmlspecialchars(ui_mask_key($license['license_key'])) ?></code>
                            <span class="cell-sub"><?= htmlspecialchars($license['product_id']) ?></span>
                        </td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($license['status']) ?>">
                                <?= ucfirst(htmlspecialchars($license['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($license['customer_name']): ?>
                                <?= htmlspecialchars($license['customer_name']) ?>
                                <?php if ($license['customer_email']): ?>
                                    <span class="cell-sub"><?= htmlspecialchars($license['customer_email']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cell-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($license['expires']): ?>
                                <?php
                                    $days_left = (strtotime($license['expires']) - time()) / 86400;
                                    $status_class = $days_left < 0 ? 'danger' : ($days_left <= 7 ? 'warning' : 'secondary');
                                ?>
                                <?= format_date($license['expires'], 'Y-m-d') ?>
                                <?php if ($days_left >= 0): ?>
                                    <span class="cell-sub"><span class="badge badge-<?= $status_class ?>"><?= floor($days_left) ?> days left</span></span>
                                <?php else: ?>
                                    <span class="cell-sub"><span class="badge badge-danger">Expired</span></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cell-muted">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $license['activation_count'] ?> / <?= $license['activation_limit'] ?: '∞' ?>
                        </td>
                        <td>
                            <?= format_date($license['created_at'], 'Y-m-d') ?>
                            <span class="cell-sub">by <?= htmlspecialchars($license['created_by_name'] ?: 'System') ?></span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn btn-sm btn-secondary btn-icon" title="Copy license key"
                                        data-copy="<?= htmlspecialchars($license['license_key']) ?>">
                                    <i class="fas fa-copy"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary btn-icon" title="Detail"
                                        data-modal-url="license-details.php?id=<?= (int) $license['id'] ?>&amp;modal=1"
                                        data-modal-title="License Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary btn-icon" title="Edit"
                                        onclick="showEditModal(<?= htmlspecialchars(json_encode($license)) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($license['status'] === 'active'): ?>
                                    <form method="POST"
                                          data-confirm="License <?= htmlspecialchars(ui_mask_key($license['license_key'])) ?> akan di-suspend dan tidak bisa dipakai sampai diaktifkan lagi."
                                          data-confirm-title="Suspend license?" data-confirm-ok="Ya, suspend" data-confirm-variant="warning">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="action" value="suspend">
                                        <input type="hidden" name="license_id" value="<?= $license['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning btn-icon" title="Suspend">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    </form>
                                <?php elseif ($license['status'] === 'suspended'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="license_id" value="<?= $license['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success btn-icon" title="Activate">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST"
                                      data-confirm="License <?= htmlspecialchars(ui_mask_key($license['license_key'])) ?> akan dihapus permanen. Tindakan ini tidak dapat dibatalkan."
                                      data-confirm-title="Hapus license?" data-confirm-ok="Ya, hapus">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="license_id" value="<?= $license['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if (isset($total_pages) && $total_pages > 1): ?>
        <?= generate_pagination($page, $total_pages, 'licenses.php', [
            'status' => $filter_status,
            'product' => $filter_product,
            'search' => $search
        ]) ?>
        <div style="text-align: center; color: var(--text-muted); margin-top: 10px;">
            <?= get_pagination_info($page, $per_page, $total_licenses) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content modal-md">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit License</h2>
            <button type="button" class="modal-close" aria-label="Tutup">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="license_id" id="edit_license_id">
            
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control" id="edit_status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="expired">Expired</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Expiration Date</label>
                <input type="date" name="expires" class="form-control" id="edit_expires">
                <div class="form-text">Leave empty for no expiration</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Activation Limit</label>
                <input type="number" name="activation_limit" class="form-control" id="edit_activation_limit" min="0" required>
                <div class="form-text">0 = unlimited</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Name</label>
                <input type="text" name="customer_name" class="form-control" id="edit_customer_name">
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Email</label>
                <input type="email" name="customer_email" class="form-control" id="edit_customer_email">
            </div>
            
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" id="edit_notes" rows="3"></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update License</button>
            </div>
        </form>
    </div>
</div>

<!-- Generate Modal (form dikirim ke generate.php; logika pembuatan key tetap di sana) -->
<div id="generateModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Generate License Keys</h2>
            <button type="button" class="modal-close" aria-label="Tutup">&times;</button>
        </div>
        <form method="POST" action="generate.php" id="generateForm">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

            <div class="generate-form-grid">
                <div>
                    <div class="form-section-title"><i class="fas fa-cog"></i> License Configuration</div>

                    <div class="form-group">
                        <label class="form-label" for="gen_count">Jumlah License Key</label>
                        <input type="number" name="count" id="gen_count" class="form-control" value="1" min="1" max="100" required>
                        <div class="form-text">Generate 1-100 license keys at once</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="gen_product">Product ID</label>
                        <input type="text" name="product_id" id="gen_product" class="form-control" value="apeiron-kit" required list="genProducts">
                        <datalist id="genProducts">
                            <?php foreach ($generate_products as $product): ?>
                                <option value="<?= htmlspecialchars($product) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="gen_status">Initial Status</label>
                        <select name="status" id="gen_status" class="form-control">
                            <option value="inactive">Inactive</option>
                            <option value="active">Active</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="gen_expires">Expiration</label>
                        <select name="expires" id="gen_expires" class="form-control">
                            <option value="never">Never Expire</option>
                            <option value="30">30 Days</option>
                            <option value="90">90 Days</option>
                            <option value="180">6 Months</option>
                            <option value="365">1 Year</option>
                            <option value="730">2 Years</option>
                            <option value="custom">Custom Date</option>
                        </select>
                    </div>

                    <div class="form-group" id="gen_custom_date_group" style="display: none;">
                        <label class="form-label" for="gen_expires_date">Custom Expiration Date</label>
                        <input type="date" name="expires_date" id="gen_expires_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="gen_limit">Activation Limit</label>
                        <input type="number" name="activation_limit" id="gen_limit" class="form-control"
                               value="<?= (int) get_setting('default_activation_limit', 1) ?>" min="0" required>
                        <div class="form-text">0 = unlimited activations</div>
                    </div>
                </div>

                <div>
                    <div class="form-section-title"><i class="fas fa-user"></i> Customer (Optional)</div>

                    <div class="form-group">
                        <label class="form-label" for="gen_customer_name">Customer Name</label>
                        <input type="text" name="customer_name" id="gen_customer_name" class="form-control" placeholder="Enter customer name">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="gen_customer_email">Customer Email</label>
                        <input type="email" name="customer_email" id="gen_customer_email" class="form-control" placeholder="customer@example.com">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="gen_notes">Notes</label>
                        <textarea name="notes" id="gen_notes" class="form-control" rows="3" placeholder="Additional notes or comments..."></textarea>
                    </div>

                    <div class="form-section-title"><i class="fas fa-lock"></i> Domain Restrictions</div>

                    <div class="form-group">
                        <label class="check-row">
                            <input type="checkbox" name="single_domain_only" value="1" id="gen_single_domain">
                            <span><strong>Single Domain Only</strong> — license hanya bisa diaktifkan di 1 domain</span>
                        </label>
                    </div>

                    <div class="form-group" id="gen_max_domains_group">
                        <label class="form-label" for="gen_max_domains">Max Domains</label>
                        <input type="number" name="max_domains" id="gen_max_domains" class="form-control" min="1" placeholder="Kosongkan untuk unlimited">
                        <div class="form-text">Maksimal jumlah domain berbeda. Kosongkan = unlimited.</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideModal('generateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="generateSubmit">
                    <i class="fas fa-plus-circle"></i> Generate License Keys
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($generated_keys)): ?>
<!-- Generated Modal: hasil generate, tampil sekali setelah redirect -->
<div id="generatedModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2><i class="fas fa-check-circle"></i> Generated License Keys <span class="badge badge-success"><?= count($generated_keys) ?> keys</span></h2>
            <button type="button" class="modal-close" aria-label="Tutup">&times;</button>
        </div>

        <div class="alert alert-warning" style="margin-bottom: 16px;">
            <i class="fas fa-exclamation-triangle"></i>
            <span>Salin key sekarang. Popup ini hanya tampil sekali.</span>
        </div>

        <div class="generated-keys-list">
            <?php foreach ($generated_keys as $index => $key_data): ?>
                <div class="generated-key-item">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="generated-key-index">#<?= $index + 1 ?></span>
                        <code class="generated-key-code" style="flex: 1; min-width: 0; overflow-wrap: anywhere;"><?= htmlspecialchars($key_data['key']) ?></code>
                        <button type="button" class="btn btn-sm btn-secondary btn-icon" title="Copy key"
                                data-copy="<?= htmlspecialchars($key_data['key']) ?>">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <div class="cell-sub" style="margin-top: 8px; padding-left: 38px;">
                        <?= htmlspecialchars($key_data['product']) ?>
                        · Expires: <?= $key_data['expires'] ? format_date($key_data['expires'], 'Y-m-d') : 'Never' ?>
                        · Limit: <?= $key_data['limit'] ?: 'Unlimited' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="hideModal('generatedModal')">Close</button>
            <button type="button" class="btn btn-primary"
                    data-copy="<?= htmlspecialchars(implode("\n", array_column($generated_keys, 'key'))) ?>">
                <i class="fas fa-copy"></i> Copy All
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function showEditModal(license) {
    document.getElementById('edit_license_id').value = license.id;
    document.getElementById('edit_status').value = license.status;
    document.getElementById('edit_expires').value = license.expires || '';
    document.getElementById('edit_activation_limit').value = license.activation_limit;
    document.getElementById('edit_customer_name').value = license.customer_name || '';
    document.getElementById('edit_customer_email').value = license.customer_email || '';
    document.getElementById('edit_notes').value = license.notes || '';
    hideModal('ajaxModal');
    showModal('editModal');
}

// FEATURE: Bulk Operations JavaScript
// ID terpilih dikirim sebagai selected_ids[] (array) sesuai yang dibaca server
function setBulkIds(form, ids) {
    const holder = form ? form.querySelector('.bulk-ids') : null;
    if (!holder) return;
    holder.innerHTML = '';
    ids.forEach(function(id) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        holder.appendChild(input);
    });
}

function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.license-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.license-checkbox:checked');
    const selectedIds = Array.from(checkboxes).map(cb => cb.value);
    const count = selectedIds.length;
    
    const bulkBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    const bulkUpdateBtn = document.getElementById('bulkUpdateBtn');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkStatusSelect = document.getElementById('bulkStatusSelect');
    
    if (!bulkBar || !selectedCount || !bulkUpdateBtn || !bulkDeleteBtn) {
        return; // Elements not found
    }
    
    if (count > 0) {
        bulkBar.classList.add('show');
        selectedCount.textContent = count + ' selected';
        bulkDeleteBtn.disabled = false;
        setBulkIds(document.getElementById('bulkDeleteForm'), selectedIds);
    } else {
        bulkBar.classList.remove('show');
        bulkUpdateBtn.disabled = true;
        bulkDeleteBtn.disabled = true;
        setBulkIds(document.getElementById('bulkDeleteForm'), []);
    }

    // Update status button
    if (bulkStatusSelect) {
        const syncStatus = function() {
            const value = bulkStatusSelect.value;
            const bulkNewStatus = document.getElementById('bulkNewStatus');
            if (value && count > 0) {
                bulkUpdateBtn.disabled = false;
                if (bulkNewStatus) bulkNewStatus.value = value;
                setBulkIds(document.getElementById('bulkStatusForm'), selectedIds);
            } else {
                bulkUpdateBtn.disabled = true;
            }
        };
        bulkStatusSelect.onchange = syncStatus;
        syncStatus();
    }
}

function clearSelection() {
    document.querySelectorAll('.license-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
    updateBulkActions();
}

// Form generate di popup
function syncGenerateForm() {
    const expires = document.getElementById('gen_expires');
    const customGroup = document.getElementById('gen_custom_date_group');
    if (expires && customGroup) {
        customGroup.style.display = expires.value === 'custom' ? 'block' : 'none';
    }
    const single = document.getElementById('gen_single_domain');
    const maxGroup = document.getElementById('gen_max_domains_group');
    const maxInput = document.getElementById('gen_max_domains');
    if (single && maxGroup) {
        maxGroup.style.display = single.checked ? 'none' : 'block';
        if (single.checked && maxInput) maxInput.value = '';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateBulkActions();

    const expires = document.getElementById('gen_expires');
    const single = document.getElementById('gen_single_domain');
    if (expires) expires.addEventListener('change', syncGenerateForm);
    if (single) single.addEventListener('change', syncGenerateForm);
    syncGenerateForm();

    // Cegah submit ganda dan beri tanda proses
    const genForm = document.getElementById('generateForm');
    if (genForm) {
        genForm.addEventListener('submit', function() {
            const btn = document.getElementById('generateSubmit');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
            }
        });
    }

    // Popup yang dibuka otomatis
    <?php if (!empty($generated_keys)): ?>
    showModal('generatedModal');
    <?php elseif ($open_generate): ?>
    showModal('generateModal');
    <?php elseif ($open_view_id > 0): ?>
    openAjaxModal('license-details.php?id=<?= $open_view_id ?>&modal=1', 'License Details');
    <?php endif; ?>

    // Bersihkan ?generate / ?view dari alamat agar refresh tidak membuka popup lagi
    if (window.history && window.history.replaceState && /[?&](generate|view)=/.test(window.location.search)) {
        const url = new URL(window.location.href);
        url.searchParams.delete('generate');
        url.searchParams.delete('view');
        window.history.replaceState(null, '', url.pathname + (url.search ? url.search : ''));
    }
});
</script>

<?php include '../includes/footer.php'; ?>