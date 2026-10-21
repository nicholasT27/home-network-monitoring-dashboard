<?php
// Scheduled scan for every account, independent of the dashboard (SCAN_MODE=live or demo on this server).
// Run from cron / Windows Task Scheduler, e.g. every 2 minutes:
//   php /path/to/network-monitor-api/cli_scan.php
// (In SCAN_MODE=agent each user's home agent schedules its own scans; run agent.php instead.)
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/monitor.php';

$pdo = db_connect($config);
$failed = false;
foreach ($pdo->query('SELECT * FROM users')->fetchAll() as $user) {
    try {
        $r = run_scan($pdo, $config, $user, 'scheduled');
        echo sprintf("[%s] %s: %d devices, %d new unknown, %.1fs\n", $r['started_at'], $user['email'], $r['devices_found'], $r['new_unknown'], $r['duration_ms'] / 1000);
    } catch (Throwable $e) {
        fwrite(STDERR, "Scan failed for {$user['email']}: " . $e->getMessage() . "\n");
        $failed = true;
    }
}
exit($failed ? 1 : 0);
