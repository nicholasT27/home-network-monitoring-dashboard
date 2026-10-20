<?php
// Minimal SMTP server for tests: php fake_smtp.php <port> <outfile>
[$self, $port, $out] = $argv;
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $s);
$c = stream_socket_accept($srv, 15);
$log = '';
$w = function ($t) use ($c) { fwrite($c, $t . "\r\n"); };
$w('220 fake ESMTP');
$data = false;
while (($line = fgets($c)) !== false) {
    $log .= $line;
    if ($data) { if (rtrim($line) === '.') { $data = false; $w('250 queued'); } continue; }
    $cmd = strtoupper(substr($line, 0, 4));
    if ($cmd === 'EHLO') $w('250 hello');
    elseif ($cmd === 'AUTH') { $w('334 VXNlcm5hbWU6'); fgets($c); $w('334 UGFzc3dvcmQ6'); fgets($c); $w('235 ok'); }
    elseif ($cmd === 'MAIL' || $cmd === 'RCPT') $w('250 ok');
    elseif ($cmd === 'DATA') { $w('354 go'); $data = true; }
    elseif ($cmd === 'QUIT') { $w('221 bye'); break; }
}
file_put_contents($out, $log);
