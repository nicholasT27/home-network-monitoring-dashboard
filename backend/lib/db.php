<?php
function db_connect(array $config): PDO
{
    $pdo = new PDO($config['db_dsn'], $config['db_user'] ?? null, $config['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
}
