<?php

namespace Tests\Support;

/**
 * Runs tests/Support/smtp_sink.php for one test: a real SMTP conversation on a free local port, every message it took
 * read back (sender, recipients, the login it was given, and the message decoded: its subject and the text a link is
 * found in). Used by the email, invite and password reset tests.
 */
final class SmtpSink
{
    public readonly int $port;

    /** @var resource */
    private $process;

    private function __construct(private readonly string $dir)
    {
        $this->process = proc_open([PHP_BINARY, __DIR__.'/smtp_sink.php', "{$dir}/port", "{$dir}/messages"], [], $pipes);
        // The sink creates this file and then writes to it, so an empty file is not an answer yet.
        $port = 0;
        for ($i = 0; $i < 200 && $port === 0; $i++) {
            usleep(10_000);
            $port = (int) @file_get_contents("{$dir}/port");
        }
        $this->port = $port;
    }

    public static function start(): self
    {
        $dir = sys_get_temp_dir().'/kit-smtp-'.bin2hex(random_bytes(6));
        mkdir($dir);

        return new self($dir);
    }

    /** A local port nothing listens on (a server that is down). */
    public static function closedPort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($s, false);
        fclose($s);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** @return list<array{from: string, to: list<string>, auth: ?array, subject: string, text: string}> */
    public function messages(): array
    {
        $file = "{$this->dir}/messages";
        if (! is_file($file)) {
            return [];
        }

        return array_map(function (string $line) {
            $m = json_decode($line, true);
            $decoded = quoted_printable_decode($m['data']);
            preg_match('/^Subject: (.*)$/m', $m['data'], $subject);

            return ['from' => $m['from'], 'to' => $m['to'], 'auth' => $m['auth'],
                'subject' => iconv_mime_decode(rtrim($subject[1] ?? '', "\r"), 0, 'UTF-8'), 'text' => $decoded];
        }, array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
    }

    /** The first link to the site in a message's text. */
    public static function link(array $message, string $site): ?string
    {
        return preg_match('#'.preg_quote($site, '#').'/[^\s"<>]+#', $message['text'], $m) ? html_entity_decode($m[0]) : null;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        @rmdir($this->dir);
    }
}
