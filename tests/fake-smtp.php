<?php
declare(strict_types=1);

// Fake SMTP server for the tests: php tests/fake-smtp.php <port> <output file>
// Accepts AUTH LOGIN, refuses "refuse@…" recipients, writes each received message to the file.

if (PHP_SAPI !== 'cli') {
    exit;
}

[$_, $port, $out] = $argv;
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "$errstr\n");
    exit(1);
}
file_put_contents("$out.ready", '1');

while ($conn = @stream_socket_accept($server, 20)) {
    $reply = fn(string $s) => fwrite($conn, $s . "\r\n");
    $reply('220 fake ESMTP');
    $inData = false;
    $message = '';
    $rcpt = [];
    while (($line = fgets($conn)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                file_put_contents($out, 'RCPT: ' . implode(',', $rcpt) . "\n" . $message . "\n=====\n", FILE_APPEND);
                $inData = false;
                $message = '';
                $rcpt = [];
                $reply('250 OK queued');
            } else {
                $message .= $line;
            }
            continue;
        }
        $command = strtoupper(trim($line));
        if (str_starts_with($command, 'EHLO')) {
            $reply('250-fake');
            $reply('250-AUTH LOGIN PLAIN');
            $reply('250 OK');
        } elseif (str_starts_with($command, 'AUTH LOGIN')) {
            $reply('334 VXNlcm5hbWU6');
            fgets($conn);
            $reply('334 UGFzc3dvcmQ6');
            fgets($conn);
            $reply('235 Authentication successful');
        } elseif (str_starts_with($command, 'RCPT TO')) {
            if (str_contains($command, 'REFUSE@')) {
                $reply('550 No such user');
            } else {
                $rcpt[] = trim(substr(trim($line), 8), ' <>');
                $reply('250 OK');
            }
        } elseif ($command === 'DATA') {
            $inData = true;
            $reply('354 Go ahead');
        } elseif ($command === 'QUIT') {
            $reply('221 Bye');
            break;
        } else {
            $reply('250 OK'); // HELO, MAIL FROM, RSET, NOOP…
        }
    }
    fclose($conn);
}
