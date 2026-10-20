<?php
// Backend tests on an in-memory SQLite database:  php backend/tests/run.php
require __DIR__ . '/../lib/monitor.php';
require __DIR__ . '/../lib/http.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

$config = ['subnet' => '192.168.1.0/24', 'scan_mode' => 'live', 'alert_email' => '', 'alert_from' => 'x@y', 'smtp_host' => '', 'smtp_user' => '',
           'scan_target_s' => 120, 'auth_secret' => 's3cret', 'token_ttl_s' => 60];
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$ms = fn() => (int) round(microtime(true) * 1000);
$dev = fn($mac, $ip, $host = null) => ['mac' => $mac, 'ip' => $ip, 'hostname' => $host];
$count = fn($sql) => (int) $pdo->query($sql)->fetchColumn();

// --- parsing / helpers
$win = "Interface: 192.168.1.5 --- 0x4\n  192.168.1.1           3c-5a-b4-11-22-33     dynamic\n  192.168.1.255         ff-ff-ff-ff-ff-ff     static\n  224.0.0.22            01-00-5e-00-00-16     static\n  10.0.0.9              aa-bb-cc-dd-ee-ff     dynamic\n";
check('parse Windows arp -a', parse_arp($win, '192.168.1.0/24') === ['192.168.1.1' => '3C:5A:B4:11:22:33']);
$lin = "? (192.168.1.20) at 8c:77:12:44:55:66 [ether] on eth0\n? (192.168.1.21) at <incomplete> on eth0\n192.168.1.30 dev eth0 lladdr a4:83:e7:aa:bb:01 REACHABLE\n";
check('parse Linux arp / ip neigh', parse_arp($lin, '192.168.1.0/24') === ['192.168.1.20' => '8C:77:12:44:55:66', '192.168.1.30' => 'A4:83:E7:AA:BB:01']);
$mac = "? (192.168.1.7) at 0:1a:2b:3c:4d:5e on en0 ifscope [ethernet]\n";
check('parse macOS arp -an (short octets)', parse_arp($mac, '192.168.1.0/24') === ['192.168.1.7' => '00:1A:2B:3C:4D:5E']);
check('cidr_hosts /24 has 254 hosts', count(cidr_hosts('192.168.1.0/24')) === 254);
check('private MAC detected', is_private_mac('DA:A1:19:00:00:01') && !is_private_mac('3C:5A:B4:11:22:33'));
check('subnet normalised to network address', normalise_subnet(' 192.168.0.124/24 ') === '192.168.0.0/24');
try { normalise_subnet('192.168.0.0/8'); $badSubnet = false; } catch (InvalidArgumentException $e) { $badSubnet = true; }
check('too-large or invalid subnet rejected', $badSubnet);

// --- accounts and tokens
$alice = create_user($pdo, 'Alice@Example.com', 'password-alice');
$bob = create_user($pdo, 'bob@example.com', 'password-bob');
check('accounts get distinct agent keys', preg_match('/^[0-9a-f]{32}$/', $alice['agent_key']) && $alice['agent_key'] !== $bob['agent_key']);
check('email is normalised and login works', login_user($pdo, 'alice@example.com', 'password-alice')['id'] === $alice['id']);
check('wrong password rejected', login_user($pdo, 'alice@example.com', 'nope-nope') === null);
check('agent key finds its owner only', user_by_agent_key($pdo, $bob['agent_key'])['id'] === $bob['id'] && user_by_agent_key($pdo, 'zz') === null);
foreach ([['alice@example.com', 'another-pass', 'duplicate email'], ['not-an-email', 'password1', 'bad email'], ['c@example.com', 'short', 'short password']] as [$e, $p, $why]) {
    try { create_user($pdo, $e, $p); $rejected = false; } catch (InvalidArgumentException $ex) { $rejected = true; }
    check("sign-up rejected: $why", $rejected);
}
$t = make_token($config, (int) $alice['id']);
check('token returns its user id', token_user($config, $t) === (int) $alice['id']);
check('tampered token rejected', token_user($config, $t . 'x') === null && token_user(array_merge($config, ['auth_secret' => 'other']), $t) === null);
check('expired token rejected', token_user(array_merge($config, ['token_ttl_s' => -5]), make_token(array_merge($config, ['token_ttl_s' => -5]), 1)) === null);

$uid = (int) $alice['id'];
$bid = (int) $bob['id'];

// --- first scan: baseline
$r = process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'), $dev('A4:83:E7:AA:BB:01', '192.168.1.12', 'kee-macbook')], 'manual', $ms());
check('first scan is a baseline: unknown devices, one summary alert', $r['new_unknown'] === 2 && $count("SELECT COUNT(*) FROM devices WHERE user_id=$uid AND status='unknown'") === 2
    && $count("SELECT COUNT(*) FROM alerts WHERE type='baseline'") === 1 && $count('SELECT COUNT(*) FROM alerts') === 1);
$pdo->exec("UPDATE devices SET status='trusted' WHERE user_id=$uid");

// --- rescan: no duplicate alerts; DHCP IP change raises no alert
$r = process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'), $dev('A4:83:E7:AA:BB:01', '192.168.1.99', 'kee-macbook')], 'manual', $ms());
check('rescan does not re-alert', $r['new_unknown'] === 0 && $count('SELECT COUNT(*) FROM alerts') === 1);
check('IP change recorded, not alerted', $count("SELECT COUNT(*) FROM connection_history WHERE event='ip_changed'") === 1
    && $pdo->query("SELECT ip FROM devices WHERE mac='A4:83:E7:AA:BB:01'")->fetchColumn() === '192.168.1.99');

// --- device leaves, then rejoins
process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan')], 'manual', $ms());
check('missing device marked offline', $count("SELECT online FROM devices WHERE mac='A4:83:E7:AA:BB:01'") === 0);
process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'), $dev('A4:83:E7:AA:BB:01', '192.168.1.99', 'kee-macbook')], 'manual', $ms());
check('rejoin logged', $count("SELECT COUNT(*) FROM connection_history WHERE event='joined'") === 1);

// --- MAC randomisation: trusted phone returns with a new private MAC and the same hostname
$pdo->exec("INSERT INTO devices (user_id, mac, ip, hostname, vendor, status, online, first_seen, last_seen) VALUES ($uid,'DA:A1:19:00:00:00','192.168.1.40','keesiang-iphone','Private (randomised) MAC','trusted',0,'2026-01-01 00:00:00','2026-01-01 00:00:00')");
$alertsBefore = $count('SELECT COUNT(*) FROM alerts');
$base = [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'), $dev('A4:83:E7:AA:BB:01', '192.168.1.99', 'kee-macbook')];
process_scan($pdo, $config, $uid, array_merge($base, [$dev('DA:A1:19:00:00:01', '192.168.1.41', 'KeeSiang-iPhone')]), 'manual', $ms());
check('rotated MAC adopted without alert', $count('SELECT COUNT(*) FROM alerts') === $alertsBefore
    && $count("SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:01' AND status='trusted'") === 1
    && $count("SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:00'") === 0
    && $count("SELECT COUNT(*) FROM connection_history WHERE event='mac_rotated'") === 1);

// --- a genuinely new device after the baseline is alerted
process_scan($pdo, $config, $uid, array_merge($base, [$dev('DA:A1:19:00:00:01', '192.168.1.41', 'keesiang-iphone'), $dev('5E:CF:7F:0B:AD:01', '192.168.1.77')]), 'manual', $ms());
check('new device after baseline raises unknown_device alert', $count("SELECT COUNT(*) FROM alerts WHERE type='unknown_device'") === 1);

// --- clone: same hostname while the trusted original is still online must not be adopted
process_scan($pdo, $config, $uid, array_merge($base, [$dev('DA:A1:19:00:00:01', '192.168.1.41', 'keesiang-iphone'), $dev('DA:A1:19:00:00:77', '192.168.1.60', 'keesiang-iphone')]), 'manual', $ms());
check('hostname clone while original online is unknown', $count("SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:77' AND status='unknown'") === 1);

// --- spoof check: trusted MAC suddenly reports a different hostname
process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'evil-laptop'), $dev('A4:83:E7:AA:BB:01', '192.168.1.99', 'kee-macbook')], 'manual', $ms());
check('trusted device with changed hostname flagged suspicious', $count("SELECT suspicious FROM devices WHERE mac='3C:5A:B4:11:22:33'") === 1
    && $count("SELECT COUNT(*) FROM alerts WHERE type='suspicious_device'") === 1);
process_scan($pdo, $config, $uid, [$dev('3C:5A:B4:11:22:33', '192.168.1.1', 'evil-laptop')], 'manual', $ms());
check('suspicious alert not repeated', $count("SELECT COUNT(*) FROM alerts WHERE type='suspicious_device'") === 1);

// --- stale ARP entry: a device that no longer answers pings must go offline
$pdo->exec("UPDATE devices SET online = 1 WHERE mac='A4:83:E7:AA:BB:01'");
process_scan($pdo, $config, $uid, [['mac' => 'A4:83:E7:AA:BB:01', 'ip' => '192.168.1.99', 'hostname' => 'kee-macbook', 'alive' => false]], 'manual', $ms());
check('device with only a stale ARP entry goes offline', $count("SELECT online FROM devices WHERE mac='A4:83:E7:AA:BB:01'") === 0);
$before = $count('SELECT COUNT(*) FROM alerts');
process_scan($pdo, $config, $uid, [['mac' => '6A:11:22:33:44:55', 'ip' => '192.168.1.90', 'hostname' => null, 'alive' => false]], 'manual', $ms());
check('ARP-only unknown device is still detected (alert) but not shown online',
    $count("SELECT online FROM devices WHERE mac='6A:11:22:33:44:55'") === 0 && $count('SELECT COUNT(*) FROM alerts') === $before + 1);

// --- households are isolated: Bob has seen none of Alice's devices
check("Bob sees none of Alice's data", $count("SELECT COUNT(*) FROM devices WHERE user_id=$bid") === 0
    && $count("SELECT COUNT(*) FROM alerts WHERE user_id=$bid") === 0 && $count("SELECT COUNT(*) FROM scans WHERE user_id=$bid") === 0);
process_scan($pdo, $config, $bid, [$dev('3C:5A:B4:11:22:33', '192.168.0.1', 'router.lan')], 'manual', $ms());
check("Bob's first device is unknown while Alice's identical MAC stays trusted",
    $pdo->query("SELECT status FROM devices WHERE user_id=$bid AND mac='3C:5A:B4:11:22:33'")->fetchColumn() === 'unknown'
    && $pdo->query("SELECT status FROM devices WHERE user_id=$uid AND mac='3C:5A:B4:11:22:33'")->fetchColumn() === 'trusted');

// --- scan lock (per user)
$demoUser = user_by_id($pdo, $uid);
$demo = array_merge($config, ['scan_mode' => 'demo']);
check('scan runs, then lock is released', is_array(run_scan($pdo, $demo, $demoUser)) && $count("SELECT COUNT(*) FROM settings WHERE k LIKE 'scan_lock%'") === 0);
check('second scan runs after the first finished', is_array(run_scan($pdo, $demo, $demoUser)));
$pdo->exec("INSERT INTO settings (k, v) VALUES ('scan_lock_$uid', '" . time() . "')");
try { run_scan($pdo, $demo, $demoUser); $blocked = false; } catch (RuntimeException $e) { $blocked = true; }
check('concurrent scan for the same user is rejected', $blocked);
check("another user's scan is not blocked by Alice's lock", is_array(run_scan($pdo, $demo, user_by_id($pdo, $bid))));
$pdo->exec("UPDATE settings SET v = '" . (time() - 1000) . "' WHERE k='scan_lock_$uid'");
check('stale lock is ignored', is_array(run_scan($pdo, $demo, $demoUser)));

// --- home agent ingest: untrusted payload is validated, results processed like a local scan
$carol = create_user($pdo, 'carol@example.com', 'password-carol');
$payload = ['duration_ms' => 4200, 'devices' => [
    ['mac' => 'aa-bb-cc-dd-ee-01', 'ip' => '192.168.1.50', 'hostname' => 'agent-laptop', 'alive' => true],
    ['mac' => 'not-a-mac', 'ip' => '192.168.1.51', 'alive' => true],
    ['mac' => 'AA:BB:CC:DD:EE:02', 'ip' => '999.1.1.1', 'alive' => true],
    ['mac' => 'AA:BB:CC:DD:EE:03', 'ip' => '192.168.1.52', 'hostname' => '', 'alive' => false],
]];
$pdo->prepare('UPDATE users SET scan_requested = ? WHERE id = ?')->execute([time(), $carol['id']]);
$r = ingest_agent_scan($pdo, $config, $carol, $payload);
check('agent ingest keeps only valid devices', $r['devices_found'] === 2 && $r['duration_ms'] >= 4200
    && $count("SELECT COUNT(*) FROM devices WHERE user_id={$carol['id']} AND mac='AA:BB:CC:DD:EE:01' AND online=1") === 1
    && $count("SELECT COUNT(*) FROM devices WHERE user_id={$carol['id']} AND mac='AA:BB:CC:DD:EE:03' AND online=0") === 1
    && $count("SELECT COUNT(*) FROM scans WHERE user_id={$carol['id']} AND source='agent'") === 1);
check('agent ingest clears the scan request and stays in its own account', (int) user_by_id($pdo, (int) $carol['id'])['scan_requested'] === 0
    && $count("SELECT COUNT(*) FROM devices WHERE mac='AA:BB:CC:DD:EE:01' AND user_id <> {$carol['id']}") === 0);

// --- SMTP alert email against a fake local server
$port = 20000 + random_int(0, 9000); $outFile = sys_get_temp_dir() . '/netmon_smtp_test.txt'; @unlink($outFile);
$srvProc = proc_open([PHP_BINARY, __DIR__ . '/fake_smtp.php', (string) $port, $outFile], [], $srvPipes);
usleep(500000);
$smtpCfg = array_merge($config, ['smtp_host' => '127.0.0.1', 'smtp_port' => $port, 'smtp_secure' => 'none', 'smtp_user' => 'me@example.com', 'smtp_pass' => 'pw', 'alert_from' => '']);
$sent = send_alert_email($smtpCfg, 'teacher@example.com', 'Network alert: test', "Line one\n.dot line\nIP: 1.2.3.4");
proc_close($srvProc);
$log = (string) @file_get_contents($outFile);
check('SMTP alert delivered to the fake server', $sent && str_contains($log, 'RCPT TO:<teacher@example.com>')
    && str_contains($log, 'Subject: Network alert: test') && str_contains($log, 'IP: 1.2.3.4') && str_contains($log, '..dot line'));
check('invalid recipient is not sent', !send_alert_email($smtpCfg, 'not-an-email', 's', 'b'));

// --- configuration from environment variables (cloud hosting), no config.local.php needed
$cfgFile = __DIR__ . '/../config.local.php';
$run = proc_open([PHP_BINARY, '-r', 'echo json_encode(require "' . __DIR__ . '/../config.php");'],
    [1 => ['pipe', 'w']], $pp, null, ['SUBNET' => '10.0.0.0/24', 'SCAN_MODE' => 'agent', 'DB_HOST' => 'db.example.com', 'DB_NAME' => 'x',
    'SMTP_HOST' => 'smtp.gmail.com', 'SMTP_PORT' => '465', 'ALLOWED_ORIGINS' => 'https://app.example.com', 'ALLOW_REGISTRATION' => '0']);
$cfg = json_decode(stream_get_contents($pp[1]), true); proc_close($run);
check('config reads environment variables', is_file($cfgFile) || ($cfg['subnet'] === '10.0.0.0/24' && $cfg['scan_mode'] === 'agent' && $cfg['smtp_port'] === 465
    && str_contains($cfg['db_dsn'], 'host=db.example.com') && in_array('https://app.example.com', $cfg['allowed_origins'], true) && $cfg['allow_registration'] === false));

check('scans recorded', $count('SELECT COUNT(*) FROM scans') >= 9);
exit($fail ? 1 : 0);
