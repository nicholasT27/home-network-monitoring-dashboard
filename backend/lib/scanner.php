<?php
// Network discovery. A ping sweep first makes every active device answer, which fills the host's
// ARP cache; only then is the cache read. Reading the cache alone misses idle devices.

require_once __DIR__ . '/vendor.php';

function cidr_hosts(string $cidr): array
{
    if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m) || !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new InvalidArgumentException("Invalid subnet: $cidr");
    }
    $prefix = (int) $m[2];
    if ($prefix < 22 || $prefix > 30) {
        throw new InvalidArgumentException('Subnet prefix must be between /22 and /30');
    }
    $mask = (~0 << (32 - $prefix)) & 0xFFFFFFFF;
    $network = ip2long($m[1]) & $mask;
    $broadcast = $network | (~$mask & 0xFFFFFFFF);
    $hosts = [];
    for ($i = $network + 1; $i < $broadcast; $i++) {
        $hosts[] = long2ip($i);
    }
    return $hosts;
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    return in_array($ip, cidr_hosts($cidr), true);
}

function is_windows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

function ping_sweep(array $ips, int $batch = 64): void
{
    foreach (array_chunk($ips, $batch) as $chunk) {
        $procs = [];
        foreach ($chunk as $ip) {
            // Timeout units differ: Windows -w and macOS -W are milliseconds, Linux -W is seconds.
            $cmd = is_windows() ? ['ping', '-n', '1', '-w', '500', $ip]
                : (PHP_OS_FAMILY === 'Darwin' ? ['ping', '-c', '1', '-W', '1000', $ip] : ['ping', '-c', '1', '-W', '1', $ip]);
            $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $p = @proc_open($cmd, $spec, $pipes);
            if (is_resource($p)) {
                $procs[] = [$p, $pipes];
            }
        }
        foreach ($procs as [$p, $pipes]) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($p);
        }
    }
}

function normalise_mac(string $mac): string
{
    return strtoupper(str_replace('-', ':', $mac));
}

// Parses `arp -a` (Windows/macOS/Linux) and `ip neigh` output into [ip => mac], inside $cidr only.
function parse_arp(string $text, string $cidr): array
{
    $hosts = array_flip(cidr_hosts($cidr));
    $found = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (!preg_match('/(\d{1,3}(?:\.\d{1,3}){3}).*?\b((?:[0-9a-f]{1,2}[:-]){5}[0-9a-f]{1,2})\b/i', $line, $m)) {
            continue;
        }
        $parts = preg_split('/[:-]/', $m[2]);
        $mac = normalise_mac(implode(':', array_map(fn($p) => str_pad($p, 2, '0', STR_PAD_LEFT), $parts)));
        $ip = $m[1];
        if (!isset($hosts[$ip]) || $mac === 'FF:FF:FF:FF:FF:FF' || $mac === '00:00:00:00:00:00'
            || str_starts_with($mac, '01:00:5E')) {
            continue;
        }
        $found[$ip] = $mac;
    }
    return $found;
}

function read_arp_cache(string $cidr): array
{
    $outputs = is_windows() ? ['arp -a'] : ['ip neigh show 2>/dev/null', 'arp -an 2>/dev/null'];
    $found = [];
    foreach ($outputs as $cmd) {
        $found += parse_arp((string) shell_exec($cmd), $cidr);
    }
    return $found;
}

function resolve_hostname(string $ip): ?string
{
    $name = @gethostbyaddr($ip);
    return ($name === false || $name === $ip || $name === '') ? null : $name;
}

// Returns [['mac' => ..., 'ip' => ..., 'hostname' => ...], ...]
function scan_network(array $config, ?int $deadline = null): array
{
    if (($config['scan_mode'] ?? 'live') === 'demo') {
        return demo_scan();
    }
    $cidr = $config['subnet'];
    ping_sweep(cidr_hosts($cidr));
    $devices = [];
    foreach (read_arp_cache($cidr) as $ip => $mac) {
        // Skip reverse-DNS lookups if the time budget is nearly spent, so the scan still finishes.
        $skipDns = $deadline !== null && time() > $deadline;
        $devices[] = ['mac' => $mac, 'ip' => $ip, 'hostname' => $skipDns ? null : resolve_hostname($ip)];
    }
    return $devices;
}

// Simulated network for demonstrations: a fixed household, plus an intruder from the 3rd scan on.
function demo_scan(): array
{
    static $n = 0;
    $file = sys_get_temp_dir() . '/netmon_demo_counter';
    $n = (int) @file_get_contents($file) + 1;
    @file_put_contents($file, (string) $n);

    $devices = [
        ['3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'],
        ['A4:83:E7:AA:BB:01', '192.168.1.12', 'kee-macbook'],
        ['8C:77:12:44:55:66', '192.168.1.20', 'living-room-tv'],
        ['24:0A:C4:9A:12:34', '192.168.1.31', 'smart-plug-kitchen'],
        ['44:65:0D:77:88:99', '192.168.1.33', 'echo-dot'],
    ];
    // A phone with a rotating private MAC: same hostname, new MAC every 4 scans.
    $devices[] = [sprintf('DA:A1:19:00:00:%02X', intdiv($n, 4)), '192.168.1.40', 'keesiang-iphone'];
    if ($n >= 3) {
        $devices[] = ['5E:CF:7F:0B:AD:01', '192.168.1.77', null];
    }
    return array_map(fn($d) => ['mac' => $d[0], 'ip' => $d[1], 'hostname' => $d[2]], $devices);
}
