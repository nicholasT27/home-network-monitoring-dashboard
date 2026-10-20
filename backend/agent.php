<?php
// Home agent: scans the local network and sends the results to the (cloud) API.
// Run it on an always-on computer inside the home network:
//   php agent.php          keep running: scans when the dashboard asks, and every AGENT_INTERVAL_S seconds
//   php agent.php --once   scan once and exit
// Settings (env vars or backend/.env): AGENT_API_URL, AGENT_KEY, SUBNET, AGENT_INTERVAL_S
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/scanner.php';

if ($config['agent_api_url'] === '' || $config['agent_key'] === '') {
    fwrite(STDERR, "Set AGENT_API_URL and AGENT_KEY first (see backend/.env.example).\n");
    exit(1);
}

function agent_call(array $config, string $action, ?array $body = null): ?array
{
    $url = $config['agent_api_url'] . (str_contains($config['agent_api_url'], '?') ? '&' : '?') . 'action=' . $action;
    $headers = ['Content-Type: application/json', 'X-Agent-Key: ' . $config['agent_key']];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    }
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $out, true);
    if ($code !== 200 || !is_array($data)) {
        fwrite(STDERR, "[" . date('H:i:s') . "] API error $action ($code): " . substr((string) $out, 0, 200) . "\n");
        return null;
    }
    return $data;
}

function agent_scan_and_send(array $config): void
{
    $start = microtime(true);
    $found = scan_network(['subnet' => $config['subnet'], 'scan_mode' => 'live'], time() + 25);
    $result = agent_call($config, 'agent_ingest', [
        'devices' => $found,
        'duration_ms' => (int) round((microtime(true) - $start) * 1000),
    ]);
    $alive = count(array_filter($found, fn($d) => $d['alive']));
    echo '[' . date('H:i:s') . '] scanned ' . count($found) . " devices ($alive answering), "
        . ($result ? "sent: {$result['new_unknown']} new unknown" : 'NOT sent') . "\n";
}

if (in_array('--once', $argv, true)) {
    agent_scan_and_send($config);
    exit(0);
}

echo "Home agent running. Subnet {$config['subnet']}, API {$config['agent_api_url']}. Ctrl+C to stop.\n";
$last = 0;
while (true) {
    $poll = agent_call($config, 'agent_poll');
    if (($poll['scan_requested'] ?? false) || time() - $last >= $config['agent_interval_s']) {
        agent_scan_and_send($config);
        $last = time();
    }
    sleep(5);
}
