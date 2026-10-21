<?php
// Alert emails: SMTP when smtp_host is configured (e.g. Gmail), otherwise PHP's mail().

function send_alert_email(array $config, string $to, string $subject, string $body): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $from = ($config['alert_from'] ?? '') ?: (($config['smtp_user'] ?? '') ?: 'network-monitor@localhost');
    if (!empty($config['smtp_host'])) {
        return smtp_send($config, $from, $to, $subject, $body);
    }
    return @mail($to, $subject, $body, "From: $from\r\nContent-Type: text/plain; charset=utf-8");
}

function smtp_send(array $c, string $from, string $to, string $subject, string $body): bool
{
    $scheme = ($c['smtp_secure'] ?? 'ssl') === 'ssl' ? 'ssl' : 'tcp';
    $fp = @stream_socket_client("$scheme://{$c['smtp_host']}:{$c['smtp_port']}", $errno, $errstr, 10);
    if (!$fp) {
        error_log("network-monitor: SMTP connect failed: $errstr");
        return false;
    }
    stream_set_timeout($fp, 10);
    $read = function () use ($fp): string {
        $reply = '';
        while (($line = fgets($fp, 515)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $reply;
    };
    $send = function (string $line, string $expect) use ($fp, $read): bool {
        fwrite($fp, $line . "\r\n");
        $reply = $read();
        if (!str_starts_with($reply, $expect)) {
            error_log('network-monitor: SMTP unexpected reply: ' . trim($reply));
            return false;
        }
        return true;
    };

    $body = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $body));   // dot-stuffing
    $message = "From: $from\r\nTo: $to\r\nSubject: $subject\r\nDate: " . date('r')
        . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . str_replace("\n", "\r\n", $body);

    $ok = str_starts_with($read(), '220')
        && $send('EHLO localhost', '250')
        && (empty($c['smtp_user'])
            || ($send('AUTH LOGIN', '334') && $send(base64_encode($c['smtp_user']), '334') && $send(base64_encode($c['smtp_pass']), '235')))
        && $send("MAIL FROM:<$from>", '250')
        && $send("RCPT TO:<$to>", '250')
        && $send('DATA', '354')
        && $send($message . "\r\n.", '250');
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}
