<?php
// Scan orchestration: compares each detected device against the stored list and raises alerts.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/scanner.php';
require_once __DIR__ . '/mailer.php';

function add_history(PDO $pdo, string $mac, ?string $ip, ?string $host, string $event, string $at): void
{
    $pdo->prepare('INSERT INTO connection_history (mac, ip, hostname, event, event_time) VALUES (?,?,?,?,?)')
        ->execute([$mac, $ip, $host, $event, $at]);
}

function raise_alert(PDO $pdo, array $config, string $type, string $mac, ?string $ip, ?string $host, string $message, string $at): void
{
    $to = get_setting($pdo, 'alert_email', $config['alert_email'] ?? '');
    $sent = false;
    if ($to !== '' && ($config['scan_mode'] ?? 'live') !== 'demo') {
        $subject = $type === 'unknown_device' ? 'Network alert: unknown device detected' : 'Network alert: suspicious device';
        $body = "$message\n\nIP address:  " . ($ip ?? '-') . "\nMAC address: $mac\nHostname:    " . ($host ?? '-')
            . "\nDetected at: $at\n\nOpen your dashboard to trust or investigate this device.";
        $sent = send_alert_email($to, $config['alert_from'], $subject, $body);
    }
    $pdo->prepare('INSERT INTO alerts (mac, ip, hostname, type, message, email_sent, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$mac, $ip, $host, $type, $message, $sent ? 1 : 0, $at]);
}

function flag_suspicious(PDO $pdo, array $config, array $dev, ?string $ip, ?string $host, string $reason, string $at): void
{
    $pdo->prepare('UPDATE devices SET suspicious = 1, suspicious_reason = ? WHERE mac = ?')->execute([$reason, $dev['mac']]);
    $name = $dev['label'] ?: ($dev['hostname'] ?: $dev['mac']);
    raise_alert($pdo, $config, 'suspicious_device', $dev['mac'], $ip, $host, "Trusted device \"$name\" looks different: $reason", $at);
}

function same_name(?string $a, ?string $b): bool
{
    return $a !== null && $b !== null && strcasecmp($a, $b) === 0;
}

// Applies one scan result to the database and returns a summary.
function process_scan(PDO $pdo, array $config, array $found, string $source, int $startedMs): array
{
    $at = now();
    $seen = [];
    $newUnknown = 0;
    $foundMacs = array_column($found, 'mac');
    // The very first scan is a baseline: the whole household is "new", so it gets one summary alert
    // instead of one alert (and email) per device.
    $baseline = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn() === 0;

    foreach ($found as $d) {
        $mac = $d['mac'];
        $ip = $d['ip'];
        $host = $d['hostname'];
        $vendor = vendor_for_mac($mac);
        $seen[$mac] = true;

        $st = $pdo->prepare('SELECT * FROM devices WHERE mac = ?');
        $st->execute([$mac]);
        $dev = $st->fetch();

        if ($dev) {
            if (!$dev['online']) {
                add_history($pdo, $mac, $ip, $host, 'joined', $at);
            }
            if ($dev['ip'] !== $ip) {
                add_history($pdo, $mac, $ip, $host, 'ip_changed', $at);   // routine DHCP change: no alert
            }
            $pdo->prepare('UPDATE devices SET ip = ?, online = 1, last_seen = ?, hostname = COALESCE(hostname, ?), vendor = COALESCE(vendor, ?) WHERE mac = ?')
                ->execute([$ip, $at, $host, $vendor, $mac]);

            // MAC-spoofing safety check: a trusted device must not change identity.
            if ($dev['status'] === 'trusted' && !$dev['suspicious']) {
                if ($host !== null && $dev['hostname'] !== null && !same_name($host, $dev['hostname'])) {
                    flag_suspicious($pdo, $config, $dev, $ip, $host,
                        "hostname changed from {$dev['hostname']} to $host (possible MAC spoofing)", $at);
                } elseif ($vendor !== null && $dev['vendor'] !== null && $vendor !== $dev['vendor']) {
                    flag_suspicious($pdo, $config, $dev, $ip, $host,
                        "manufacturer changed from {$dev['vendor']} to $vendor", $at);
                }
            }
            continue;
        }

        // Unrecognised MAC. Is it a trusted phone that rotated its private MAC? Match on hostname, but
        // only if that trusted device is not also present right now under its old MAC.
        $rotated = false;
        if ($host !== null) {
            $st = $pdo->prepare("SELECT * FROM devices WHERE status = 'trusted' AND LOWER(hostname) = LOWER(?)");
            $st->execute([$host]);
            foreach ($st->fetchAll() as $old) {
                if (in_array($old['mac'], $foundMacs, true)) {
                    continue;
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE devices SET mac = ?, ip = ?, online = 1, last_seen = ? WHERE mac = ?')
                    ->execute([$mac, $ip, $at, $old['mac']]);
                $pdo->prepare('UPDATE connection_history SET mac = ? WHERE mac = ?')->execute([$mac, $old['mac']]);
                add_history($pdo, $mac, $ip, $host, 'mac_rotated', $at);
                $pdo->commit();
                $rotated = true;
                break;
            }
        }
        if ($rotated) {
            continue;
        }

        $pdo->prepare('INSERT INTO devices (mac, ip, hostname, vendor, status, online, first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$mac, $ip, $host, $vendor, 'unknown', 1, $at, $at]);
        add_history($pdo, $mac, $ip, $host, 'first_seen', $at);
        if (!$baseline) {
            raise_alert($pdo, $config, 'unknown_device', $mac, $ip, $host, 'An unknown device joined your network.', $at);
        }
        $newUnknown++;
    }

    if ($baseline && $newUnknown > 0) {
        $pdo->prepare('INSERT INTO alerts (mac, ip, hostname, type, message, email_sent, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute(['-', null, null, 'baseline', "First scan found $newUnknown devices. Mark the ones you recognise as Trusted; anything you don't recognise stays Unknown.", 0, $at]);
    }

    // Anything previously online but not seen this time has left.
    foreach ($pdo->query('SELECT mac, ip, hostname FROM devices WHERE online = 1')->fetchAll() as $d) {
        if (!isset($seen[$d['mac']])) {
            $pdo->prepare('UPDATE devices SET online = 0 WHERE mac = ?')->execute([$d['mac']]);
            add_history($pdo, $d['mac'], $d['ip'], $d['hostname'], 'left', $at);
        }
    }

    $duration = (int) round(microtime(true) * 1000) - $startedMs;
    $pdo->prepare('INSERT INTO scans (started_at, duration_ms, devices_found, new_unknown, source) VALUES (?,?,?,?,?)')
        ->execute([$at, $duration, count($found), $newUnknown, $source]);

    return ['started_at' => $at, 'duration_ms' => $duration, 'devices_found' => count($found), 'new_unknown' => $newUnknown];
}

function run_scan(PDO $pdo, array $config, string $source = 'manual'): array
{
    $lock = fopen(sys_get_temp_dir() . '/netmon_scan.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('A scan is already running. Wait a minute and try again; if it keeps saying this, restart Apache.');
    }
    try {
        $startedMs = (int) round(microtime(true) * 1000);
        $target = (int) ($config['scan_target_s'] ?? 120);
        @set_time_limit($target + 60);   // Apache's default 30 s limit would kill a longer scan
        // Hostnames are optional, so reverse-DNS lookups get a short budget instead of the whole scan time.
        $deadline = time() + 25;
        return process_scan($pdo, $config, scan_network($config, $deadline), $source, $startedMs);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
