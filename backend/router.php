<?php
// Router for PHP's built-in server:  php -S 127.0.0.1:8080 -t dist backend/router.php
// Serves the built dashboard from dist/ and the JSON API at /index.php.
$dist = realpath(__DIR__ . '/../dist');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
$file = realpath($dist . $path);
if ($path !== '/' && $file !== false && str_starts_with($file, $dist . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;   // let the built-in server send the static file
}
header('Content-Type: text/html; charset=utf-8');
readfile($dist . '/index.html');
return true;
