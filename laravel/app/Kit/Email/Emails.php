<?php

namespace App\Kit\Email;

use App\Models\OutgoingEmail;
use Illuminate\Support\Str;

/**
 * Sending the kit's emails (SELF_HOSTED_KIT_PLAN.md §4.7). An email of one kind is rendered (EmailTemplate), kept
 * (`emails`, its body encrypted), and sent at once through the senders in the operator's order: the first that
 * takes it sends it. One that no sender could send waits for `kit:emails` to try again after each of
 * config('kit.email.retry_minutes'), then is given up. Its body is wiped once it is sent or given up, so a link's
 * secret does not outlive the email.
 *
 * A sender is checked before it is saved by sending it one test (test()): nothing is kept, and its own error is
 * thrown.
 *
 * Not here: the senders and the site's address (Senders), the texts (lang/<locale>/emails.php), who sends what
 * (Team, PasswordResets, the wizard and Admin → Email).
 */
final class Emails
{
    public function __construct(private readonly Senders $senders, private readonly EmailTemplate $template) {}

    /** Whether the kit can send email at all. */
    public function on(): bool
    {
        return $this->senders->on();
    }

    /** Send one email of a kind to one address: now, or kept to be tried again. `$url` is its button's link. */
    public function send(string $kind, string $to, array $vars = [], ?string $url = null): OutgoingEmail
    {
        $rendered = $this->template->render($kind, $vars, $url);
        $email = (new OutgoingEmail)->forceFill([
            'kind' => $kind, 'to_address' => $to, 'subject' => $rendered['subject'],
            'body' => ['html' => $rendered['html'], 'text' => $rendered['text']], 'status' => 'queued', 'attempts' => 0,
        ]);
        $email->save();
        $this->deliver($email);

        return $email;
    }

    /**
     * Send one test email through this sender only, from this address (it may not be saved yet), keeping nothing.
     * Throws EmailFailed with the sender's reason.
     *
     * @param  array{address: string, name: string}  $from
     */
    public function test(string $driver, array $config, array $from, string $to): void
    {
        $rendered = $this->template->render('test');
        $this->senders->driver($driver)->send($config, $this->message($to, $rendered['subject'], $rendered, $from));
    }

    /** Try a waiting email again (kit:emails). */
    public function retry(OutgoingEmail $email): void
    {
        $this->deliver($email);
    }

    private function deliver(OutgoingEmail $email): void
    {
        $attempts = $email->attempts + 1;
        $errors = [];
        foreach ($this->on() ? $this->senders->all() : [] as $sender) {
            $driver = $this->senders->driver($sender['driver']);
            try {
                $driver->send($sender['config'], $this->message($email->to_address, $email->subject, $email->body ?? []));
                $email->forceFill(['status' => 'sent', 'attempts' => $attempts, 'sender_id' => $sender['id'], 'sent_at' => now(),
                    'body' => null, 'last_error' => null, 'next_attempt_at' => null])->save();

                return;
            } catch (EmailFailed $e) {
                $errors[] = $driver->label($sender['config']).': '.$e->getMessage();
            }
        }
        $waits = config('kit.email.retry_minutes');
        $error = Str::limit($errors === [] ? __('emails.no_sender') : implode(' · ', $errors), config('kit.email.error_max_length'));
        $email->forceFill($attempts <= count($waits)
            ? ['attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => now()->addMinutes($waits[$attempts - 1])]
            : ['attempts' => $attempts, 'last_error' => $error, 'status' => 'failed', 'body' => null, 'next_attempt_at' => null])->save();
    }

    /**
     * @param  array{html?: string, text?: string}  $body
     * @param  array{address: ?string, name: string}|null  $from  null: the saved one
     */
    private function message(string $to, string $subject, array $body, ?array $from = null): EmailMessage
    {
        $from ??= $this->senders->from();

        return new EmailMessage((string) $from['address'], $from['name'], $to, $subject, (string) ($body['html'] ?? ''), (string) ($body['text'] ?? ''));
    }
}
