<?php
// Scheduled scan, independent of the dashboard. Run from cron / Windows Task Scheduler, e.g. every 2 minutes:
//   php C:\xampp\htdocs\network-monitor-api\cli_scan.php
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/monitor.php';

try {
    $r = run_scan(db_connect($config), $config, 'scheduled');
    echo sprintf("[%s] %d devices, %d new unknown, %.1fs\n", $r['started_at'], $r['devices_found'], $r['new_unknown'], $r['duration_ms'] / 1000);
} catch (Throwable $e) {
    fwrite(STDERR, 'Scan failed: ' . $e->getMessage() . "\n");
    exit(1);
}
