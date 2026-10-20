<?php
// CORS headers (the fix for the cross-origin blocking issue), JSON helpers and auth tokens.

function send_cors(array $config): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $config['allowed_origins'], true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 600');
    }
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): void
{
    json_out(['error' => $message], $status);
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = $raw === '' ? [] : json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function make_token(array $config, int $userId): string
{
    $payload = b64url(json_encode(['uid' => $userId, 'exp' => time() + $config['token_ttl_s']]));
    return $payload . '.' . b64url(hash_hmac('sha256', $payload, $config['auth_secret'], true));
}

// Returns the user id the token was issued to, or null if it is invalid or expired.
function token_user(array $config, string $token): ?int
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    $expected = b64url(hash_hmac('sha256', $parts[0], $config['auth_secret'], true));
    if (!hash_equals($expected, $parts[1])) {
        return null;
    }
    $payload = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    return (is_array($payload) && ($payload['exp'] ?? 0) >= time() && isset($payload['uid'])) ? (int) $payload['uid'] : null;
}

function require_auth(array $config): int
{
    $uid = token_user($config, $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if ($uid === null) {
        fail('Not signed in', 401);
    }
    return $uid;
}
