<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Generate License Keys';
$show_header = true;

$generated_keys = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    require_csrf_token();
    
    $count = intval($_POST['count'] ?? 1);
    $expires = $_POST['expires'] ?? '';
    $activation_limit = intval($_POST['activation_limit'] ?? get_setting('default_activation_limit', 1));
    $product_id = sanitize_input($_POST['product_id'] ?? 'apeiron-kit');
    $customer_name = sanitize_input($_POST['customer_name'] ?? '');
    $customer_email = sanitize_input($_POST['customer_email'] ?? '');
    
    // VALIDATION: Validate email if provided
    if (!empty($customer_email) && !validate_email($customer_email)) {
        $_SESSION['error_message'] = 'Invalid email format for customer email';
        header('Location: generate.php');
        exit;
    }
    $status = sanitize_input($_POST['status'] ?? 'inactive');
    $notes = sanitize_input($_POST['notes'] ?? '');
    
    // Domain lock (comma-separated domains)
    $allowed_domains_raw = trim($_POST['allowed_domains'] ?? '');
    $allowed_domains = null;
    if (!empty($allowed_domains_raw)) {
        $domains = array_map('trim', explode(',', $allowed_domains_raw));
        $domains = array_filter($domains, function($domain) {
            if (empty($domain)) return false;
            // Simple domain validation (more lenient)
            $domain_clean = preg_replace('#^https?://#i', '', $domain);
            return preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain_clean);
        });
        if (!empty($domains)) {
            $allowed_domains = json_encode($domains);
        }
    }
    
    // Single domain only flag
    $single_domain_only = isset($_POST['single_domain_only']) ? 1 : 0;
    
    // Max domains (only if single_domain_only = 0)
    $max_domains = null;
    if ($single_domain_only == 0 && !empty($_POST['max_domains'])) {
        $max_domains = intval($_POST['max_domains']);
        if ($max_domains < 1) {
            $max_domains = null;
        }
    }
    
    if ($count < 1 || $count > 100) {
        $_SESSION['error_message'] = 'Count must be between 1 and 100';
    } else {
        try {
            $db = get_db_connection();
            $generated_keys = [];
            
            $db->beginTransaction();
            
            try {
                // Pre-calculate values outside loop for performance
                $expires_date = null;
                if ($expires === 'custom' && !empty($_POST['expires_date'])) {
                    $expires_date = $_POST['expires_date'];
                } elseif ($expires && $expires !== 'never') {
                    $expires_date = date('Y-m-d', strtotime("+{$expires} days"));
                }
                
                // Check encryption setting once (cache it)
                $is_encrypted = get_setting('encryption_enabled', false) ? 1 : 0;
                
                // Pre-check: Get existing keys to avoid collisions (batch check) - only for large batches
                $existing_keys = [];
                if ($count > 10) {
                    $stmt = $db->query("SELECT license_key FROM licenses WHERE license_key LIKE 'APEIRON-%' LIMIT 1000");
                    $existing_keys = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
                }
                
                // Prepare insert statement once (reuse for all inserts)
                // PERFORMANCE: Include license_key_hash for fast lookup
                $insert_stmt = $db->prepare("
                    INSERT INTO licenses (license_key, license_key_hash, client_api_key, is_encrypted, product_id, status, expires, activation_limit, single_domain_only, max_domains, customer_name, customer_email, notes, allowed_domains, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                // NEW: Prepare api_keys insert statement
                $insert_api_stmt = $db->prepare("
                    INSERT INTO api_keys (key_name, api_key, api_key_prefix, product_id, is_active, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())
                ");

                for ($i = 0; $i < $count; $i++) {
                    $max_retries = 10;
                    $retry_count = 0;
                    $license_key = null;
                    
                    // Generate unique license key with retry
                    while ($retry_count < $max_retries) {
                        $license_key = generate_license_key();
                        
                        // Quick check in memory first (for batch generation)
                        if (!empty($existing_keys) && isset($existing_keys[$license_key])) {
                            $retry_count++;
                            continue;
                        }
                        
                        // Check if key already exists in database (only if not in memory cache)
                        if (empty($existing_keys) || $count <= 10) {
                            $check_stmt = $db->prepare("SELECT id FROM licenses WHERE license_key = ? OR license_key_hash = ? LIMIT 1");
                            $license_key_hash = hash('sha256', $license_key);
                            $check_stmt->execute([$license_key, $license_key_hash]);
                            if ($check_stmt->fetch()) {
                                $retry_count++;
                                continue;
                            }
                        }
                        
                        // Add to existing keys array to avoid duplicates in same batch
                        $existing_keys[$license_key] = true;
                        break; // Key is unique
                    }
                    
                    if ($retry_count >= $max_retries) {
                        throw new Exception('Failed to generate unique license key after ' . $max_retries . ' attempts');
                    }
                    
                    // PERFORMANCE: Calculate hash for fast lookup (always, even if encrypted)
                    $license_key_hash = hash('sha256', $license_key);

                    // FIX: Generate client API key for each license
                    $client_api_key = bin2hex(random_bytes(32));

                    // Encrypt license key if encryption is enabled
                    $stored_key = $license_key;
                    if ($is_encrypted) {
                        $stored_key = encrypt_data($license_key);
                    }

                    // Insert license using prepared statement (reused for performance)
                    $insert_stmt->execute([
                        $stored_key,
                        $license_key_hash, // PERFORMANCE: Add hash for fast lookup
                        $client_api_key, // FIX: Now properly initialized
                        $is_encrypted,
                        $product_id,
                        $status,
                        $expires_date,
                        $activation_limit,
                        $single_domain_only,
                        $max_domains,
                        $customer_name ?: null,
                        $customer_email ?: null,
                        $notes ?: null,
                        $allowed_domains,
                        $_SESSION['admin_id']
                    ]);

                $license_id = $db->lastInsertId();

                // Skip if insert failed (duplicate key - should not happen with UNIQUE constraint)
                if (!$license_id) {
                    $i--; // Retry this iteration
                    continue;
                }

                // NEW: Also insert into api_keys table for Management API Key page
                $api_key_prefix = substr($client_api_key, 0, 8);
                $hashed_api_key = hash('sha256', $client_api_key);
                $key_name = 'License: ' . (!empty($customer_email) ? $customer_email : (!empty($customer_name) ? $customer_name : '#' . ($i + 1)));

                $insert_api_stmt->execute([
                    $key_name,
                    $hashed_api_key,
                    $api_key_prefix,
                    $product_id,
                    $_SESSION['admin_id']
                ]);

                $api_key_id = $db->lastInsertId();
                error_log("[TRACE] API KEY SAVED TO DB: prefix={$api_key_prefix}, id={$api_key_id}, license_id={$license_id}");

                $generated_keys[] = [
                    'id' => $license_id,
                    'key' => $license_key,
                    'expires' => $expires_date,
                    'limit' => $activation_limit,
                    'product' => $product_id
                ];
                }
                
                $db->commit();
                
                // Log activity once after all licenses are generated (outside loop for performance)
                if (!empty($generated_keys)) {
                    log_activity('create', 'license', null, "Generated {$count} license key(s)");
                }
                
                $_SESSION['success_message'] = "Berhasil generate {$count} license key(s)!";
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e; // Re-throw untuk outer catch
            }
        } catch (PDOException $e) {
            error_log('Generate License Error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Database error occurred. Please try again or contact administrator.';
        } catch (Exception $e) {
            error_log('Generate License Error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

/*
 * Tampilan Generate kini berupa popup di halaman Licenses.
 * - POST berhasil : key yang baru dibuat disimpan sekali di session lalu
 *                   ditampilkan sebagai popup "Generated" di licenses.php.
 * - POST gagal    : kembali ke licenses.php dan popup Generate dibuka lagi
 *                   (pesan error tampil dari $_SESSION['error_message']).
 * - GET           : alamat lama generate.php langsung membuka popup Generate.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($generated_keys)) {
    $_SESSION['generated_keys'] = $generated_keys;
    header('Location: licenses.php');
    exit;
}

header('Location: licenses.php?generate=1');
exit;
?>
