<?php
// Starts the Home Network Monitor on this computer:  php start.php
// Serves the dashboard + API, scans every 2 minutes in the background, opens your browser.
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$root = __DIR__;
$port = (int) (getenv('PORT') ?: 8080);
$url = "http://127.0.0.1:$port";

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "This PHP has no SQLite support. Enable extension=pdo_sqlite and extension=sqlite3 in php.ini, then run again.\n");
    exit(1);
}
if (!is_file("$root/dist/index.html")) {
    fwrite(STDERR, "dist/index.html is missing. Run `npm install && npm run build` once to build the dashboard.\n");
    exit(1);
}

$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/dist", "$root/backend/router.php"], [], $pipes);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the web server.\n");
    exit(1);
}
register_shutdown_function(fn() => proc_terminate($server));
sleep(1);
if (!proc_get_status($server)['running']) {
    fwrite(STDERR, "The web server stopped straight away. Is port $port already in use? Try: PORT=8081 php start.php\n");
    exit(1);
}

echo "\nHome Network Monitor is running at $url\n";
echo "Create an account, set your network range, then press \"Scan now\".\n";
echo "A scan also runs every 2 minutes in the background. Press Ctrl+C to stop.\n\n";

$open = match (PHP_OS_FAMILY) { 'Windows' => "start \"\" \"$url\"", 'Darwin' => "open \"$url\"", default => "xdg-open \"$url\" >/dev/null 2>&1 &" };
@shell_exec($open);

$next = time() + 120;
while (proc_get_status($server)['running']) {
    sleep(1);
    if (time() >= $next) {
        $next = time() + 120;
        $r = proc_open([PHP_BINARY, "$root/backend/cli_scan.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $sp);
        if (is_resource($r)) {
            echo stream_get_contents($sp[1]) . stream_get_contents($sp[2]);
            proc_close($r);
        }
    }
}
