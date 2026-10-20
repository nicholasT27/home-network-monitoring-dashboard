<?php
// Router for PHP's built-in server that exposes ONLY the JSON API, nothing else (no SQL files, no config).
//   php -S 127.0.0.1:8081 -t backend backend/router.php
// Use this when publishing the backend through a tunnel. (Tunnelling XAMPP's whole port 80 would also
// expose phpMyAdmin to the internet.)
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
http_response_code(404);
header('Content-Type: application/json');
echo '{"error":"Not found"}';
return true;
