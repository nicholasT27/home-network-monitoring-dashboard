<?php
// Email alerts use PHP's built-in mail(); on XAMPP configure sendmail / SMTP in php.ini first.

function send_alert_email(string $to, string $from, string $subject, string $body): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $headers = "From: $from\r\nContent-Type: text/plain; charset=utf-8";
    return @mail($to, $subject, $body, $headers);
}
