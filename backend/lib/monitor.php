<?php
// Scan orchestration: compares each detected device against the user's stored list and raises alerts.
// Every query is scoped by user_id so households never see each other's data.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/scanner.php';
require_once __DIR__ . '/mailer.php';

function add_history(PDO $pdo, int $uid, string $mac, ?string $ip, ?string $host, string $event, string $at): void
{
    $pdo->prepare('INSERT INTO connection_history (user_id, mac, ip, hostname, event, event_time) VALUES (?,?,?,?,?,?)')
        ->execute([$uid, $mac, $ip, $host, $event, $at]);
}

function raise_alert(PDO $pdo, array $config, int $uid, string $type, string $mac, ?string $ip, ?string $host, string $message, string $at): void
{
    $to = (string) (user_by_id($pdo, $uid)['alert_email'] ?? '');
    $sent = false;
    if ($to !== '' && ($config['scan_mode'] ?? 'live') !== 'demo') {
        $subject = $type === 'unknown_device' ? 'Network alert: unknown device detected' : 'Network alert: suspicious device';
        $body = "$message\n\nIP address:  " . ($ip ?? '-') . "\nMAC address: $mac\nHostname:    " . ($host ?? '-')
            . "\nDetected at: $at UTC\n\nOpen your dashboard to trust or investigate this device.";
        $sent = send_alert_email($config, $to, $subject, $body);
    }
    $pdo->prepare('INSERT INTO alerts (user_id, mac, ip, hostname, type, message, email_sent, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$uid, $mac, $ip, $host, $type, $message, $sent ? 1 : 0, $at]);
}

function flag_suspicious(PDO $pdo, array $config, int $uid, array $dev, ?string $ip, ?string $host, string $reason, string $at): void
{
    $pdo->prepare('UPDATE devices SET suspicious = 1, suspicious_reason = ? WHERE user_id = ? AND mac = ?')->execute([$reason, $uid, $dev['mac']]);
    $name = $dev['label'] ?: ($dev['hostname'] ?: $dev['mac']);
    raise_alert($pdo, $config, $uid, 'suspicious_device', $dev['mac'], $ip, $host, "Trusted device \"$name\" looks different: $reason", $at);
}

function same_name(?string $a, ?string $b): bool
{
    return $a !== null && $b !== null && strcasecmp($a, $b) === 0;
}

// Applies one scan result to the user's data and returns a summary.
function process_scan(PDO $pdo, array $config, int $uid, array $found, string $source, int $startedMs): array
{
    $at = now();
    $seen = [];
    $newUnknown = 0;
    $foundMacs = array_column($found, 'mac');
    // The very first scan is a baseline: the whole household is "new", so it gets one summary alert
    // instead of one alert (and email) per device.
    $st = $pdo->prepare('SELECT COUNT(*) FROM scans WHERE user_id = ?');
    $st->execute([$uid]);
    $baseline = (int) $st->fetchColumn() === 0;

    foreach ($found as $d) {
        $mac = $d['mac'];
        $ip = $d['ip'];
        $host = $d['hostname'];
        $vendor = vendor_for_mac($mac);
        $alive = $d['alive'] ?? true;
        if ($alive) {
            $seen[$mac] = true;
        }

        $st = $pdo->prepare('SELECT * FROM devices WHERE user_id = ? AND mac = ?');
        $st->execute([$uid, $mac]);
        $dev = $st->fetch();

        if ($dev) {
            if ($alive && !$dev['online']) {
                add_history($pdo, $uid, $mac, $ip, $host, 'joined', $at);
            }
            if ($alive && $dev['ip'] !== $ip) {
                add_history($pdo, $uid, $mac, $ip, $host, 'ip_changed', $at);   // routine DHCP change: no alert
            }
            if ($alive) {
                $pdo->prepare('UPDATE devices SET ip = ?, online = 1, last_seen = ?, hostname = COALESCE(hostname, ?), vendor = COALESCE(vendor, ?) WHERE user_id = ? AND mac = ?')
                    ->execute([$ip, $at, $host, $vendor, $uid, $mac]);
            }
            // A stale ARP entry (no ping reply) leaves the device as it was; the offline sweep below handles it.

            // MAC-spoofing safety check: a trusted device must not change identity.
            if ($dev['status'] === 'trusted' && !$dev['suspicious']) {
                if ($host !== null && $dev['hostname'] !== null && !same_name($host, $dev['hostname'])) {
                    flag_suspicious($pdo, $config, $uid, $dev, $ip, $host,
                        "hostname changed from {$dev['hostname']} to $host (possible MAC spoofing)", $at);
                } elseif ($vendor !== null && $dev['vendor'] !== null && $vendor !== $dev['vendor']) {
                    flag_suspicious($pdo, $config, $uid, $dev, $ip, $host,
                        "manufacturer changed from {$dev['vendor']} to $vendor", $at);
                }
            }
            continue;
        }

        // Unrecognised MAC. Is it a trusted phone that rotated its private MAC? Match on hostname, but
        // only if that trusted device is not also present right now under its old MAC.
        $rotated = false;
        if ($host !== null) {
            $st = $pdo->prepare("SELECT * FROM devices WHERE user_id = ? AND status = 'trusted' AND LOWER(hostname) = LOWER(?)");
            $st->execute([$uid, $host]);
            foreach ($st->fetchAll() as $old) {
                if (in_array($old['mac'], $foundMacs, true)) {
                    continue;
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE devices SET mac = ?, ip = ?, online = ?, last_seen = ? WHERE user_id = ? AND mac = ?')
                    ->execute([$mac, $ip, $alive ? 1 : 0, $at, $uid, $old['mac']]);
                $pdo->prepare('UPDATE connection_history SET mac = ? WHERE user_id = ? AND mac = ?')->execute([$mac, $uid, $old['mac']]);
                add_history($pdo, $uid, $mac, $ip, $host, 'mac_rotated', $at);
                $pdo->commit();
                $rotated = true;
                break;
            }
        }
        if ($rotated) {
            continue;
        }

        $pdo->prepare('INSERT INTO devices (user_id, mac, ip, hostname, vendor, status, online, first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$uid, $mac, $ip, $host, $vendor, 'unknown', $alive ? 1 : 0, $at, $at]);
        add_history($pdo, $uid, $mac, $ip, $host, 'first_seen', $at);
        if (!$baseline) {
            raise_alert($pdo, $config, $uid, 'unknown_device', $mac, $ip, $host, 'An unknown device joined your network.', $at);
        }
        $newUnknown++;
    }

    if ($baseline && $newUnknown > 0) {
        $pdo->prepare('INSERT INTO alerts (user_id, mac, ip, hostname, type, message, email_sent, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$uid, '-', null, null, 'baseline', "First scan found $newUnknown devices. Mark the ones you recognise as Trusted; anything you don't recognise stays Unknown.", 0, $at]);
    }

    // Anything previously online but not seen this time has left.
    $st = $pdo->prepare('SELECT mac, ip, hostname FROM devices WHERE user_id = ? AND online = 1');
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $d) {
        if (!isset($seen[$d['mac']])) {
            $pdo->prepare('UPDATE devices SET online = 0 WHERE user_id = ? AND mac = ?')->execute([$uid, $d['mac']]);
            add_history($pdo, $uid, $d['mac'], $d['ip'], $d['hostname'], 'left', $at);
        }
    }

    $duration = (int) round(microtime(true) * 1000) - $startedMs;
    $pdo->prepare('INSERT INTO scans (user_id, started_at, duration_ms, devices_found, new_unknown, source) VALUES (?,?,?,?,?,?)')
        ->execute([$uid, $at, $duration, count($found), $newUnknown, $source]);

    return ['started_at' => $at, 'duration_ms' => $duration, 'devices_found' => count($found), 'new_unknown' => $newUnknown];
}

// The scan lock lives in the database (not a temp file) so it works for both Apache and the CLI user.
// A lock older than the stale limit is ignored, so a crashed scan can never block scanning for good.
function acquire_scan_lock(PDO $pdo, int $uid, int $staleSeconds): bool
{
    $key = 'scan_lock_' . $uid;
    $pdo->prepare('DELETE FROM settings WHERE k = ? AND CAST(v AS SIGNED) < ?')->execute([$key, time() - $staleSeconds]);
    try {
        $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, (string) time()]);
        return true;
    } catch (PDOException $e) {
        return false;   // primary-key clash: another scan holds the lock
    }
}

function release_scan_lock(PDO $pdo, int $uid): void
{
    $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute(['scan_lock_' . $uid]);
}

// Scans on this server (modes "live" and "demo") for one user's network range.
function run_scan(PDO $pdo, array $config, array $user, string $source = 'manual'): array
{
    $uid = (int) $user['id'];
    $target = (int) ($config['scan_target_s'] ?? 120);
    if (!acquire_scan_lock($pdo, $uid, $target + 60)) {
        throw new RuntimeException('A scan is already running. Wait a minute and try again.');
    }
    try {
        $startedMs = (int) round(microtime(true) * 1000);
        @set_time_limit($target + 60);   // Apache's default 30 s limit would kill a longer scan
        $st = $pdo->prepare('SELECT COUNT(*) FROM scans WHERE user_id = ?');
        $st->execute([$uid]);
        $round = (int) $st->fetchColumn() + 1;
        // Hostnames are optional, so reverse-DNS lookups get a short budget instead of the whole scan time.
        $found = scan_network(['subnet' => user_subnet($user, $config), 'scan_mode' => $config['scan_mode']], time() + 25, $round);
        return process_scan($pdo, $config, $uid, $found, $source, $startedMs);
    } finally {
        release_scan_lock($pdo, $uid);
    }
}

// Applies a scan sent by a user's home agent. The payload is untrusted input: validate every device.
function ingest_agent_scan(PDO $pdo, array $config, array $user, array $payload): array
{
    $uid = (int) $user['id'];
    $devices = [];
    foreach (array_slice((array) ($payload['devices'] ?? []), 0, 1024) as $d) {
        $mac = normalise_mac((string) ($d['mac'] ?? ''));
        $ip = (string) ($d['ip'] ?? '');
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            continue;
        }
        $host = isset($d['hostname']) && is_string($d['hostname']) && $d['hostname'] !== '' ? mb_substr($d['hostname'], 0, 255) : null;
        $devices[] = ['mac' => $mac, 'ip' => $ip, 'hostname' => $host, 'alive' => !empty($d['alive'])];
    }
    $target = (int) ($config['scan_target_s'] ?? 120);
    if (!acquire_scan_lock($pdo, $uid, $target + 60)) {
        throw new RuntimeException('A scan is already being processed. Try again shortly.');
    }
    try {
        $duration = max(0, min((int) ($payload['duration_ms'] ?? 0), 3600000));
        $summary = process_scan($pdo, $config, $uid, $devices, 'agent', (int) round(microtime(true) * 1000) - $duration);
        $pdo->prepare('UPDATE users SET scan_requested = 0 WHERE id = ?')->execute([$uid]);
        return $summary;
    } finally {
        release_scan_lock($pdo, $uid);
    }
}
