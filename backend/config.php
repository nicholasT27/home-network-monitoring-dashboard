<?php
// Default settings. Override any of them in config.local.php (not committed to git):
//   <?php return ['db_pass' => 'secret', 'subnet' => '192.168.0.0/24'];

$defaults = [
    'db_dsn'       => 'mysql:host=127.0.0.1;dbname=home_network_monitor;charset=utf8mb4',
    'db_user'      => 'root',
    'db_pass'      => '',

    // Network range to scan (CIDR, at most 1024 hosts). The report's test range is 192.168.1.0/24.
    'subnet'       => '192.168.1.0/24',
    // 'live' pings the subnet and reads the ARP cache; 'demo' returns simulated devices.
    'scan_mode'    => 'live',
    // Requirement: a full scan must finish in under this many seconds.
    'scan_target_s' => 120,

    // Dashboard login. The default password is "changeme": change it before real use.
    // Generate a hash with:  php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    'dashboard_password_hash' => '$2y$10$EpgLh5dgXlGICo6BCq/Ffua2N/zREaadl9.LqM9Zz3IsURCWLN6Vi',
    'auth_secret'  => 'change-this-long-random-string',
    'token_ttl_s'  => 43200,

    // Origins allowed by CORS (the React dev server and preview server).
    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173',
                          'http://localhost:4173', 'http://127.0.0.1:4173'],

    // Email alerts use PHP mail(). Leave alert_email empty to disable. Can also be set from the dashboard.
    'alert_email'  => '',
    'alert_from'   => 'network-monitor@localhost',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $defaults = array_merge($defaults, require $local);
}
return $defaults;
