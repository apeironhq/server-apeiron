<?php
/**
 * Portal License Info API Endpoint
 *
 * Endpoint: POST /api/portal_license.php
 * Auth:     X-API-Key (kunci portal), tanpa site_url
 *
 * KENAPA ENDPOINT BARU, BUKAN MEMAKAI check.php
 * check.php menjawab pertanyaan "apakah lisensi ini aktif DI SITUS INI".
 * Ia mewajibkan site_url, dan statusnya ditentukan oleh ada tidaknya baris
 * activations untuk situs tersebut — tanpa activation ia mengembalikan
 * 'inactive'. Portal bukan situs pelanggan dan tidak punya site_url, jadi
 * memanggil check.php dari portal akan selalu melaporkan 'inactive' meskipun
 * lisensinya aktif di situs pelanggan. Itu status yang menyesatkan, bukan
 * sekadar kurang lengkap.
 *
 * Endpoint ini menjawab pertanyaan yang berbeda: "apa keadaan lisensi ini".
 * Statusnya milik lisensi, bukan milik satu situs.
 *
 * HANYA MEMBACA. Tidak ada satu pun UPDATE/INSERT di berkas ini — aktivasi,
 * validasi, dan perubahan status tetap sepenuhnya milik activate.php,
 * deactivate.php, dan check.php.
 */

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';

set_cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Method not allowed',
        'error_code' => 'METHOD_NOT_ALLOWED'
    ], 405);
}

$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);
if ($input === null && !empty($_POST)) {
    $input = $_POST;
}

$product_id = trim($input['product_id'] ?? 'apeiron-kit');

// site_url sengaja null: portal bukan situs pelanggan, jadi tidak ada domain
// yang bisa ia klaim. Kunci API portal yang menjadi dasar kepercayaan.
$api_key_info = require_api_auth(false, null, $product_id);
if ($api_key_info === false) {
    exit;
}

$client_ip = get_client_ip();
require_rate_limit(
    $client_ip,
    $api_key_info['rate_limit_requests'] ?? 100,
    $api_key_info['rate_limit_window'] ?? 3600,
    $api_key_info['id'] ?? null
);

$license_key = trim($input['license_key'] ?? '');
if (empty($license_key)) {
    json_response([
        'success' => false,
        'message' => 'License key is required',
        'error_code' => 'MISSING_LICENSE_KEY'
    ], 400);
}

$license_key = sanitize_input($license_key);
$product_id = sanitize_input($product_id);

try {
    $db = get_db_connection();

    // Pencarian sama persis dengan check.php: hash dulu (cepat), lalu teks
    // biasa untuk baris lama, lalu terenkripsi bila fiturnya menyala.
    $license_key_hash = hash('sha256', $license_key);

    $stmt = $db->prepare("
        SELECT * FROM licenses
        WHERE license_key_hash = ?
          AND product_id = ?
          AND (is_encrypted = 0 OR is_encrypted IS NULL)
    ");
    $stmt->execute([$license_key_hash, $product_id]);
    $license = $stmt->fetch();

    if (!$license) {
        $stmt = $db->prepare("
            SELECT * FROM licenses
            WHERE license_key = ?
              AND product_id = ?
              AND (is_encrypted = 0 OR is_encrypted IS NULL)
        ");
        $stmt->execute([$license_key, $product_id]);
        $license = $stmt->fetch();
    }

    if (!$license && get_setting('encryption_enabled', false)) {
        $stmt = $db->prepare("
            SELECT * FROM licenses
            WHERE license_key = ?
              AND product_id = ?
              AND is_encrypted = 1
        ");
        $stmt->execute([encrypt_data($license_key), $product_id]);
        $license = $stmt->fetch();
    }

    if (!$license) {
        json_response([
            'success' => false,
            'message' => 'License key tidak ditemukan',
            'error_code' => 'LICENSE_NOT_FOUND'
        ], 404);
    }

    // -------------------------------------------------------------------
    // Lifetime = tidak punya tanggal berakhir.
    //
    // Kolom license_type belum ada di tabel licenses, jadi "lifetime"
    // ditentukan dari expires yang kosong — persis cara check.php menilai
    // kedaluwarsa (`if ($license['expires'] && ...)`). Kalau nanti kolom
    // license_type ditambahkan, kolom itulah yang harus dipakai.
    // -------------------------------------------------------------------
    $expires_raw = $license['expires'] ?? null;
    $is_lifetime = empty($expires_raw) || $expires_raw === '0000-00-00 00:00:00';

    $now = time();
    $expires_ts = $is_lifetime ? null : strtotime($expires_raw);
    $is_expired = !$is_lifetime && $expires_ts !== false && $expires_ts < $now;

    // Sisa hari dihitung di sini, bukan di portal, supaya angkanya mengikuti
    // jam server — satu-satunya jam yang menentukan kedaluwarsa sebenarnya.
    // ceil(): sisa 0,3 hari tetap "1 hari lagi", bukan "0 hari lagi" pada
    // lisensi yang masih berlaku.
    $days_remaining = null;
    if (!$is_lifetime && $expires_ts !== false) {
        $days_remaining = $is_expired ? 0 : (int) ceil(($expires_ts - $now) / 86400);
    }

    // Status tingkat lisensi. Sengaja TIDAK memakai logika per-situs milik
    // check.php ('inactive' saat situs ini belum diaktifkan).
    $status = $license['status'] ?? 'unknown';
    if ($is_expired) {
        $status = 'expired';
    }

    // Aktivasi: berapa yang terpakai, dan kapan pertama kali diaktifkan.
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM activations WHERE license_id = ?");
    $stmt->execute([$license['id']]);
    $row = $stmt->fetch();
    $activations_used = (int) ($row['c'] ?? 0);

    $stmt = $db->prepare("
        SELECT MIN(activated_at) AS first_activated
        FROM activations
        WHERE license_id = ? AND status = 'active'
    ");
    $stmt->execute([$license['id']]);
    $row = $stmt->fetch();
    $activated_at = $row['first_activated'] ?? null;

    // Label durasi diturunkan dari rentang terbit → berakhir. Ini turunan,
    // bukan data tersimpan; lihat catatan license_type di atas.
    $duration_label = 'Lifetime';
    if (!$is_lifetime) {
        $issued_ts = strtotime($license['created_at'] ?? '');
        $span_days = ($issued_ts && $expires_ts) ? (int) round(($expires_ts - $issued_ts) / 86400) : null;

        if ($span_days === null) {
            $duration_label = 'Berjangka';
        } elseif ($span_days >= 355) {
            $duration_label = (int) round($span_days / 365) . ' Tahun';
        } elseif ($span_days >= 28) {
            $duration_label = (int) round($span_days / 30) . ' Bulan';
        } else {
            $duration_label = $span_days . ' Hari';
        }
    }

    json_response([
        'success' => true,
        'message' => 'License info berhasil diambil',
        'data' => [
            'license_key'      => $license_key,
            'product_id'       => $license['product_id'],
            'status'           => $status,
            'is_lifetime'      => $is_lifetime,
            'duration_label'   => $duration_label,
            'issued_at'        => $license['created_at'] ?? null,
            'activated_at'     => $activated_at,
            'expires_at'       => $is_lifetime ? null : $expires_raw,
            'days_remaining'   => $days_remaining,
            'activations_used' => $activations_used,
            'activation_limit' => (int) ($license['activation_limit'] ?? 0),
            'checked_at'       => gmdate('Y-m-d H:i:s'),
        ]
    ]);

} catch (PDOException $e) {
    error_log('Database error in portal_license.php: ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => (defined('ENVIRONMENT') && ENVIRONMENT === 'production')
            ? 'Server error occurred. Please try again later.'
            : 'Database error: ' . $e->getMessage(),
        'error_code' => 'SERVER_ERROR'
    ], 500);
} catch (Exception $e) {
    error_log('Error in portal_license.php: ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => (defined('ENVIRONMENT') && ENVIRONMENT === 'production')
            ? 'Server error occurred. Please try again later.'
            : 'Error: ' . $e->getMessage(),
        'error_code' => 'SERVER_ERROR'
    ], 500);
}
