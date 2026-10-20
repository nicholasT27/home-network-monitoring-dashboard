<?php
function db_connect(array $config): PDO
{
    $dsn = $config['db_dsn'];
    $isSqlite = str_starts_with($dsn, 'sqlite:');
    if ($isSqlite) {
        sqlite_prepare_file(substr($dsn, 7));
    }
    $pdo = new PDO($dsn, $config['db_user'] ?? null, $config['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($isSqlite) {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        // First run: create the tables from the bundled schema.
        if ((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn() === 0) {
            $pdo->exec(file_get_contents(__DIR__ . '/../schema.sqlite.sql'));
        }
    }
    return $pdo;
}

// Makes sure the SQLite file's folder exists and is not served over the web.
function sqlite_prepare_file(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');   // all stored times are UTC; the dashboard converts to local time
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
