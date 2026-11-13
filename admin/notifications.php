<?php
/**
 * Notifikasi request API yang gagal (JSON, hanya baca).
 *
 * Dipanggil oleh lonceng notifikasi di topbar (assets/js/main.js).
 * Sumber data: tabel api_logs yang diisi log_api_request() di endpoint API.
 * Hanya membaca 2000 baris terbaru (lewat primary key) supaya tetap ringan
 * walaupun tabelnya besar.
 */

require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Penjelasan singkat (Bahasa Indonesia) untuk pesan gagal dari API */
function notif_explain(string $message, int $code): string {
    $m = strtolower($message);
    $map = [
        'request origin mismatch' => 'Asal request tidak cocok dengan site_url yang dikirim. Bisa jadi key dipakai dari domain lain.',
        'license key not found' => 'License key tidak terdaftar di server. Bisa salah ketik atau key palsu.',
        'api key missing' => 'Request dikirim tanpa API key.',
        'api key not found' => 'API key tidak dikenal oleh server.',
        'api key expired' => 'API key sudah kedaluwarsa.',
        'invalid api key' => 'API key salah atau tidak aktif.',
        'domain not allowed for this api key' => 'Domain ini tidak diizinkan memakai API key tersebut.',
        'ip address not allowed for this api key' => 'IP pengirim tidak diizinkan memakai API key tersebut.',
        'database error' => 'Server gagal mengakses database saat memproses request.',
        'server error' => 'Terjadi error di server saat memproses request.',
    ];
    foreach ($map as $needle => $text) {
        if (strpos($m, $needle) === 0) return $text;
    }
    if ($code >= 500) return 'Terjadi error di server saat memproses request.';
    if ($code === 401 || $code === 403) return 'Request ditolak karena tidak memiliki izin.';
    if ($code === 404) return 'Data yang diminta tidak ditemukan.';
    return 'Request ditolak oleh server.';
}

/** Pesan dari response_data tanpa detail teknis (mis. isi exception) */
function notif_message($json): string {
    $data = json_decode((string) $json, true);
    if (!is_array($data)) return '';
    $msg = (string) ($data['message'] ?? ($data['error_code'] ?? ''));
    // "Database error: SQLSTATE..." -> "Database error"
    $msg = preg_replace('/:\s.*$/s', '', $msg);
    return trim($msg);
}

function notif_host($url): string {
    $url = trim((string) $url);
    if ($url === '') return '';
    $host = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'http://' . $url, PHP_URL_HOST);
    return strtolower($host ?: $url);
}

$result = [
    'ok' => true,
    'logging_enabled' => (bool) get_setting('enable_api_logging', true),
    'failed_24h' => 0,
    'latest_id' => 0,
    'items' => [],
];

try {
    $db = get_db_connection();
    $columns = $db->query("SHOW COLUMNS FROM api_logs")->fetchAll(PDO::FETCH_COLUMN);
    $has_created_at = in_array('created_at', $columns, true);

    $time_select = $has_created_at ? ', created_at' : '';
    $recent = "SELECT id, endpoint, site_url, status_code, ip_address, response_data{$time_select}
               FROM api_logs ORDER BY id DESC LIMIT 2000";

    // Jumlah gagal 24 jam terakhir (dari jendela baris terbaru)
    if ($has_created_at) {
        $count = $db->query("
            SELECT COUNT(*) FROM ({$recent}) t
            WHERE t.status_code >= 400 AND t.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ")->fetchColumn();
        $result['failed_24h'] = (int) $count;
    }

    $rows = $db->query("
        SELECT * FROM ({$recent}) t
        WHERE t.status_code >= 400
        ORDER BY t.id DESC
        LIMIT 15
    ")->fetchAll();

    foreach ($rows as $row) {
        $code = (int) $row['status_code'];
        $message = notif_message($row['response_data']);
        $result['items'][] = [
            'id' => (int) $row['id'],
            'endpoint' => (string) $row['endpoint'],
            'site' => notif_host($row['site_url']),
            'ip' => (string) ($row['ip_address'] ?? ''),
            'status' => $code,
            'message' => $message !== '' ? $message : ('HTTP ' . $code),
            'explain' => notif_explain($message, $code),
            'time' => $has_created_at ? (string) $row['created_at'] : '',
            'ago' => $has_created_at && $row['created_at'] ? time_ago($row['created_at']) : '',
        ];
    }

    $result['latest_id'] = $result['items'] ? $result['items'][0]['id'] : 0;
} catch (Exception $e) {
    $result['ok'] = false;
    $result['error'] = 'Data request belum tersedia.';
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
