<?php

namespace App\Kit\Email\Drivers;

use App\Kit\Email\EmailDriver;
use App\Kit\Email\EmailFailed;
use App\Kit\Email\EmailMessage;
use App\Kit\Setup\Field;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * The built-in email driver: any SMTP server (the operator's own, or a provider's SMTP relay). Its encryption is one
 * of config('kit.email.smtp.encryptions'): `tls` upgrades with STARTTLS and refuses a server that cannot (so a
 * password is never sent in the clear by mistake), `ssl` connects over TLS, `none` never encrypts. Each send opens
 * its own connection and closes it.
 *
 * Not here: which sender goes first (Senders), retrying (Emails).
 */
final class SmtpDriver implements EmailDriver
{
    public function fields(array $config): array
    {
        $smtp = config('kit.email.smtp');
        $text = fn (string $key) => __("emails.drivers.smtp.{$key}");

        return [
            new Field('host', $text('fields.host'), 'text', true, $config['host'] ?? null),
            new Field('port', $text('fields.port'), 'text', true, (string) ($config['port'] ?? $smtp['default_port'])),
            new Field('encryption', $text('fields.encryption'), 'select', true, $config['encryption'] ?? $smtp['encryptions'][0], $text('help.encryption'),
                array_map(fn (string $e) => ['value' => $e, 'label' => $text("encryptions.{$e}")], $smtp['encryptions'])),
            new Field('username', $text('fields.username'), 'text', false, $config['username'] ?? null),
            new Field('password', $text('fields.password'), 'secret', false, null, null, [], null, ($config['password'] ?? '') !== ''),
        ];
    }

    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'string', Rule::in(config('kit.email.smtp.encryptions'))],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function secrets(): array
    {
        return ['password'];
    }

    public function label(array $config): string
    {
        return __('emails.drivers.smtp.label', ['host' => $config['host'], 'port' => $config['port'],
            'encryption' => __("emails.drivers.smtp.encryptions.{$config['encryption']}")]);
    }

    public function send(array $config, EmailMessage $message): void
    {
        $transport = new EsmtpTransport((string) $config['host'], (int) $config['port'], $config['encryption'] === 'ssl');
        $transport->setAutoTls($config['encryption'] !== 'none');
        $transport->setRequireTls($config['encryption'] === 'tls');
        if (($config['username'] ?? '') !== '') {
            $transport->setUsername((string) $config['username']);
            $transport->setPassword((string) ($config['password'] ?? ''));
        }
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) config('kit.email.smtp.timeout_seconds'));
        }
        try {
            $transport->send($message->toMime());
        } catch (TransportExceptionInterface $e) {
            throw new EmailFailed($e->getMessage(), 0, $e);
        } finally {
            try {
                $transport->stop();
            } catch (TransportExceptionInterface) {
                // Closing a connection that already failed: nothing more to say.
            }
        }
    }
}
