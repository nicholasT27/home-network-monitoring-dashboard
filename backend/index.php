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
    $pdo = db_connect($config);

    // ---- Public: create an account / sign in
    if ($action === 'register' && $method === 'POST') {
        if (!$config['allow_registration']) fail('Sign-ups are closed', 403);
        $b = json_body();
        try {
            $user = create_user($pdo, (string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        json_out(['token' => make_token($config, (int) $user['id'])]);
    }
    if ($action === 'login' && $method === 'POST') {
        $b = json_body();
        $user = login_user($pdo, (string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
        if (!$user) {
            sleep(1);   // slows down password guessing
            fail('Incorrect email or password', 401);
        }
        json_out(['token' => make_token($config, (int) $user['id'])]);
    }

    // ---- Home agent: authenticated by the user's personal agent key, not a dashboard login
    if (str_starts_with($action, 'agent_')) {
        $agent = user_by_agent_key($pdo, (string) ($_SERVER['HTTP_X_AGENT_KEY'] ?? ''));
        if (!$agent) fail('Invalid agent key', 401);
        $pdo->prepare('UPDATE users SET agent_seen = ? WHERE id = ?')->execute([time(), $agent['id']]);
        if ($action === 'agent_poll') {
            $requested = (int) $agent['scan_requested'];
            json_out(['scan_requested' => $requested > 0 && time() - $requested < 300, 'subnet' => user_subnet($agent, $config)]);
        }
        if ($action === 'agent_ingest' && $method === 'POST') {
            json_out(ingest_agent_scan($pdo, $config, $agent, json_body()));
        }
        fail('Unknown agent action', 404);
    }

    // ---- Signed-in user
    $uid = require_auth($config);
    $user = user_by_id($pdo, $uid) ?: fail('Not signed in', 401);

    switch ($action) {
        case 'status':
            $st = $pdo->prepare('SELECT * FROM scans WHERE user_id = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$uid]);
            json_out([
                'email' => $user['email'],
                'last_scan' => $st->fetch() ?: null,
                'subnet' => user_subnet($user, $config),
                'mode' => $config['scan_mode'],
                'scan_target_s' => $config['scan_target_s'],
                'alert_email' => $user['alert_email'] ?? '',
                'agent_key' => $user['agent_key'],
                'agent_online' => time() - (int) $user['agent_seen'] < 60,
            ]);

        case 'devices':
            $st = $pdo->prepare('SELECT * FROM devices WHERE user_id = ? ORDER BY online DESC, last_seen DESC');
            $st->execute([$uid]);
            json_out($st->fetchAll());

        case 'scan':
            if ($method !== 'POST') fail('POST required', 405);
            if ($config['scan_mode'] === 'agent') {   // the user's home agent picks this up within seconds
                $pdo->prepare('UPDATE users SET scan_requested = ? WHERE id = ?')->execute([time(), $uid]);
                json_out(['queued' => true]);
            }
            json_out(run_scan($pdo, $config, $user, 'manual'));

        case 'alerts':
            $st = $pdo->prepare('SELECT * FROM alerts WHERE user_id = ? AND acknowledged_at IS NULL ORDER BY id DESC LIMIT 100');
            $st->execute([$uid]);
            json_out($st->fetchAll());

        case 'alert_ack':
            $id = json_body()['id'] ?? null;
            if ($id === 'all') {
                $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND acknowledged_at IS NULL')->execute([now(), $uid]);
            } else {
                $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND id = ?')->execute([now(), $uid, (int) $id]);
            }
            json_out(['ok' => true]);

        case 'device_update':
            $b = json_body();
            $mac = strtoupper((string) ($b['mac'] ?? ''));
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) fail('Invalid MAC address');
            if (isset($b['status'])) {
                if (!in_array($b['status'], ['trusted', 'unknown'], true)) fail('Invalid status');
                // Trusting a device resets any suspicion; its current identity becomes the baseline.
                $pdo->prepare('UPDATE devices SET status = ?, suspicious = 0, suspicious_reason = NULL WHERE user_id = ? AND mac = ?')->execute([$b['status'], $uid, $mac]);
                if ($b['status'] === 'trusted') {
                    $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND mac = ? AND acknowledged_at IS NULL')->execute([now(), $uid, $mac]);
                }
            }
            if (array_key_exists('label', $b)) {
                $label = trim((string) $b['label']);
                $pdo->prepare('UPDATE devices SET label = ? WHERE user_id = ? AND mac = ?')->execute([$label === '' ? null : mb_substr($label, 0, 100), $uid, $mac]);
            }
            if (!empty($b['clear_suspicious'])) {
                $pdo->prepare('UPDATE devices SET suspicious = 0, suspicious_reason = NULL WHERE user_id = ? AND mac = ?')->execute([$uid, $mac]);
                $pdo->prepare('UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND mac = ? AND acknowledged_at IS NULL')->execute([now(), $uid, $mac]);
            }
            json_out(['ok' => true]);

        case 'device_delete':
            $mac = strtoupper((string) (json_body()['mac'] ?? ''));
            $pdo->prepare('DELETE FROM devices WHERE user_id = ? AND mac = ?')->execute([$uid, $mac]);
            $pdo->prepare('DELETE FROM connection_history WHERE user_id = ? AND mac = ?')->execute([$uid, $mac]);
            $pdo->prepare('DELETE FROM alerts WHERE user_id = ? AND mac = ?')->execute([$uid, $mac]);
            json_out(['ok' => true]);

        case 'history':
            $mac = strtoupper((string) ($_GET['mac'] ?? ''));
            if ($mac !== '') {
                $st = $pdo->prepare('SELECT * FROM connection_history WHERE user_id = ? AND mac = ? ORDER BY id DESC LIMIT 100');
                $st->execute([$uid, $mac]);
            } else {
                $st = $pdo->prepare('SELECT * FROM connection_history WHERE user_id = ? ORDER BY id DESC LIMIT 100');
                $st->execute([$uid]);
            }
            json_out($st->fetchAll());

        case 'settings':   // the user's own network range and alert email
            $b = json_body();
            if (array_key_exists('subnet', $b)) {
                try {
                    $subnet = trim((string) $b['subnet']) === '' ? null : normalise_subnet((string) $b['subnet']);
                } catch (InvalidArgumentException $e) {
                    fail('Enter the network range like 192.168.0.0/24 (prefix between /22 and /30)');
                }
                $pdo->prepare('UPDATE users SET subnet = ? WHERE id = ?')->execute([$subnet, $uid]);
            }
            if (array_key_exists('alert_email', $b)) {
                $email = trim((string) $b['alert_email']);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address');
                $pdo->prepare('UPDATE users SET alert_email = ? WHERE id = ?')->execute([$email === '' ? null : $email, $uid]);
            }
            json_out(['ok' => true]);

        case 'test_email':
            if ($method !== 'POST') fail('POST required', 405);
            $to = (string) ($user['alert_email'] ?? '');
            if ($to === '') fail('Save an email address first');
            $sent = send_alert_email($config, $to, 'Network Monitor: test email', "This is a test email from your Home Network Monitor.\nIf you can read this, alert emails are working.");
            json_out(['sent' => $sent, 'to' => $to, 'via' => empty($config['smtp_host']) ? 'mail()' : 'smtp']);

        default:
            fail('Unknown action', 404);
    }
} catch (PDOException $e) {
    error_log('network-monitor: ' . $e->getMessage());
    fail('Database error. Check that MySQL is running and backend/schema.sql was imported.', 500);
} catch (RuntimeException $e) {
    fail($e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('network-monitor: ' . $e->getMessage());
    fail('Server error', 500);
}
