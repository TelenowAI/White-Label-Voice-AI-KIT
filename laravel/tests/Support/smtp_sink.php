<?php

/**
 * A tiny SMTP server for the kit's tests and its end-to-end check — never a real mail server:
 *
 *     php smtp_sink.php <port file> <messages file> [port]
 *
 * It listens on 127.0.0.1 (the given port, or a free one), writes the port it got to the port file, and takes one
 * connection after another. It offers AUTH PLAIN and no STARTTLS (so a client that requires TLS must refuse it), and
 * appends each message as one JSON line to the messages file: {from, to, auth: {username, password}|null, data}.
 * Started and read by Tests\Support\SmtpSink; the e2e copy runs it on a fixed port.
 */
[$portFile, $messagesFile] = [$argv[1], $argv[2]];
$server = stream_socket_server('tcp://127.0.0.1:'.($argv[3] ?? '0'), $errno, $error);
if ($server === false) {
    fwrite(STDERR, "smtp_sink: {$error}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
file_put_contents($portFile, substr($name, strrpos($name, ':') + 1));

while (($conn = @stream_socket_accept($server, -1)) !== false) {
    $reply = fn (string $line) => fwrite($conn, $line."\r\n");
    $reply('220 smtp-sink ESMTP');
    $auth = null;
    $message = ['from' => null, 'to' => []];
    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");
        $verb = strtoupper(strtok($line, ' ') ?: '');
        if ($verb === 'EHLO' || $verb === 'HELO') {
            $reply('250-smtp-sink');
            $reply('250-AUTH PLAIN');
            $reply('250 8BITMIME');
        } elseif ($verb === 'AUTH') {
            $parts = explode(' ', $line);
            $plain = $parts[2] ?? null;
            if ($plain === null) {
                $reply('334 ');
                $plain = rtrim((string) fgets($conn), "\r\n");
            }
            [, $username, $password] = array_pad(explode("\0", (string) base64_decode($plain)), 3, null);
            $auth = ['username' => $username, 'password' => $password];
            $reply('235 2.7.0 Authentication successful');
        } elseif ($verb === 'MAIL') {
            $message['from'] = trim(substr($line, strpos($line, ':') + 1), ' <>');
            $reply('250 2.1.0 OK');
        } elseif ($verb === 'RCPT') {
            $message['to'][] = trim(substr($line, strpos($line, ':') + 1), ' <>');
            $reply('250 2.1.5 OK');
        } elseif ($verb === 'DATA') {
            $reply('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($d = fgets($conn)) !== false && rtrim($d, "\r\n") !== '.') {
                $data .= str_starts_with($d, '..') ? substr($d, 1) : $d;
            }
            file_put_contents($messagesFile, json_encode($message + ['auth' => $auth, 'data' => $data])."\n", FILE_APPEND | LOCK_EX);
            $message = ['from' => null, 'to' => []];
            $reply('250 2.0.0 Queued');
        } elseif ($verb === 'QUIT') {
            $reply('221 2.0.0 Bye');
            break;
        } else {
            $reply('250 2.0.0 OK');
        }
    }
    fclose($conn);
}
