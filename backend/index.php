<?php
// JSON API router:  index.php?action=<name>
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/monitor.php';

send_cors($config);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($action === 'login' && $method === 'POST') {
        $password = (string) (json_body()['password'] ?? '');
        if (!password_verify($password, $config['dashboard_password_hash'])) {
            sleep(1);   // slows down password guessing
            fail('Incorrect password', 401);
        }
        json_out(['token' => make_token($config)]);
    }

    require_auth($config);
    $pdo = db_connect($config);

    switch ($action) {
        case 'status':
            $last = $pdo->query('SELECT * FROM scans ORDER BY id DESC LIMIT 1')->fetch() ?: null;
            json_out([
                'last_scan' => $last,
                'subnet' => $config['subnet'],
                'mode' => $config['scan_mode'],
                'scan_target_s' => $config['scan_target_s'],
                'alert_email' => get_setting($pdo, 'alert_email', $config['alert_email']),
            ]);

        case 'devices':
            json_out($pdo->query('SELECT * FROM devices ORDER BY online DESC, last_seen DESC')->fetchAll());

        case 'scan':
            if ($method !== 'POST') fail('POST required', 405);
            json_out(run_scan($pdo, $config, 'manual'));

        case 'alerts':
            $sql = 'SELECT * FROM alerts' . (isset($_GET['all']) ? '' : ' WHERE acknowledged_at IS NULL') . ' ORDER BY id DESC LIMIT 100';
            json_out($pdo->query($sql)->fetchAll());

        case 'alert_ack':
            $id = json_body()['id'] ?? null;
            if ($id === 'all') {
                $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE acknowledged_at IS NULL')->execute([now()]);
            } else {
                $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE id = ?')->execute([now(), (int) $id]);
            }
            json_out(['ok' => true]);

        case 'device_update':
            $b = json_body();
            $mac = strtoupper((string) ($b['mac'] ?? ''));
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) fail('Invalid MAC address');
            if (isset($b['status'])) {
                if (!in_array($b['status'], ['trusted', 'unknown'], true)) fail('Invalid status');
                // Trusting a device resets any suspicion; its current identity becomes the baseline.
                $pdo->prepare('UPDATE devices SET status = ?, suspicious = 0, suspicious_reason = NULL WHERE mac = ?')->execute([$b['status'], $mac]);
                if ($b['status'] === 'trusted') {
                    $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE mac = ? AND acknowledged_at IS NULL')->execute([now(), $mac]);
                }
            }
            if (array_key_exists('label', $b)) {
                $label = trim((string) $b['label']);
                $pdo->prepare('UPDATE devices SET label = ? WHERE mac = ?')->execute([$label === '' ? null : mb_substr($label, 0, 100), $mac]);
            }
            if (!empty($b['clear_suspicious'])) {
                $pdo->prepare('UPDATE devices SET suspicious = 0, suspicious_reason = NULL WHERE mac = ?')->execute([$mac]);
                $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE mac = ? AND acknowledged_at IS NULL')->execute([now(), $mac]);
            }
            json_out(['ok' => true]);

        case 'device_delete':
            $mac = strtoupper((string) (json_body()['mac'] ?? ''));
            foreach (['devices', 'connection_history', 'alerts'] as $t) {
                $pdo->prepare("DELETE FROM $t WHERE mac = ?")->execute([$mac]);
            }
            json_out(['ok' => true]);

        case 'history':
            $mac = strtoupper((string) ($_GET['mac'] ?? ''));
            if ($mac !== '') {
                $st = $pdo->prepare('SELECT * FROM connection_history WHERE mac = ? ORDER BY id DESC LIMIT 100');
                $st->execute([$mac]);
                json_out($st->fetchAll());
            }
            json_out($pdo->query('SELECT * FROM connection_history ORDER BY id DESC LIMIT 100')->fetchAll());

        case 'settings':
            if ($method === 'POST') {
                $email = trim((string) (json_body()['alert_email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address');
                set_setting($pdo, 'alert_email', $email);
            }
            json_out(['alert_email' => get_setting($pdo, 'alert_email', $config['alert_email'])]);

        default:
            fail('Unknown action', 404);
    }
} catch (RuntimeException $e) {
    fail($e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('network-monitor: ' . $e->getMessage());
    fail('Server error', 500);
}
