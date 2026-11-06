<?php

namespace App\Kit\Events;

use App\Kit\Telenow\TelenowError;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The events inbox (SELF_HOSTED_KIT_PLAN.md §3.8 c, e; §5 C4): Telenow's deliveries are verified, stored raw and
 * answered at once; applying them is a job's (applyDue — right after the answer, and `kit:events` every minute).
 *
 * - The signature is `X-VoiceAI-Signature: sha256=<HMAC-SHA256 of the raw body>` with the endpoint's secret (the one
 *   before it too, for deliveries signed before a re-registration).
 * - De-duplicated on `X-VoiceAI-Delivery`: the signature has no timestamp, so this is also the replay protection.
 * - Never 410: Telenow would stop sending to the endpoint for good. 503 while there is no endpoint (try again later).
 * - Each event goes to its handler (config('kit.events.handlers')); one with none is kept as applied, untouched.
 *   A handler that throws has its event tried again after base × 2^(tries − 1) seconds, given up after max_attempts.
 *
 * Not here: registering the endpoint (Endpoint), what an event changes (the handlers).
 */
final class Inbox
{
    public function __construct(private readonly Endpoint $endpoint, private readonly Container $container) {}

    /** Store one delivery. Answers the status for Telenow: 204 once stored (or stored before), else why not. */
    public function receive(string $raw, ?string $signature, ?string $delivery, ?string $event): int
    {
        $secrets = $this->endpoint->secrets();
        if ($secrets === []) {
            return 503;
        }
        if (! $this->signed($raw, $signature, $secrets)) {
            return 401;
        }
        $body = json_decode($raw, true);
        if (! is_array($body) || ! is_string($delivery) || $delivery === '') {
            return 400;
        }
        $now = now();
        TelenowEvent::query()->insertOrIgnore([
            'delivery_id' => $delivery,
            'event' => is_string($event) && $event !== '' ? $event : (string) ($body['event'] ?? ''),
            'body' => $raw,
            'sub_account_id' => is_string($body['subAccountId'] ?? null) ? $body['subAccountId'] : null,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 204;
    }

    /** Apply up to config('kit.events.batch') due events, oldest first. Answers how many were applied. */
    public function applyDue(): int
    {
        $applied = 0;
        $due = TelenowEvent::query()->whereNull('applied_at')->whereNull('failed_at')->where('next_attempt_at', '<=', now())
            ->orderBy('id')->limit(config('kit.events.batch'))->get();
        foreach ($due as $event) {
            $applied += (int) $this->attempt($event);
        }

        return $applied;
    }

    /** Apply a given-up event again, now (Admin → Voice platform). Answers whether it was applied this time. */
    public function retry(TelenowEvent $event): bool
    {
        if ($event->applied_at !== null || $event->failed_at === null) {
            return false;
        }
        $event->forceFill(['failed_at' => null, 'attempts' => 0, 'next_attempt_at' => now(), 'locked_until' => null])->save();

        return $this->attempt($event);
    }

    /** Forget applied events after config('kit.events.keep_days') (their raw bodies hold call data). */
    public function prune(): int
    {
        return TelenowEvent::query()->whereNotNull('applied_at')
            ->where('applied_at', '<', now()->subDays(config('kit.events.keep_days')))->delete();
    }

    /** @param list<string> $secrets */
    private function signed(string $raw, ?string $signature, array $secrets): bool
    {
        if (! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }
        $given = substr($signature, strlen('sha256='));
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $raw, $secret), $given)) {
                return true;
            }
        }

        return false;
    }

    private function attempt(TelenowEvent $event): bool
    {
        $now = now();
        $claimed = TelenowEvent::query()->whereKey($event->id)->whereNull('applied_at')->whereNull('failed_at')
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->update(['locked_until' => $now->copy()->addSeconds(config('kit.events.lock_seconds')), 'updated_at' => $now]);
        if ($claimed !== 1) {
            return false; // another run has it
        }
        $event->refresh();
        $event->attempts++;
        // The map's keys hold a dot ('call.completed'), so it is read whole: config() would take the dot as nesting.
        $class = config('kit.events.handlers')[$event->event] ?? null;
        try {
            if ($class !== null) {
                /** @var EventHandler $handler */
                $handler = $this->container->make($class);
                $handler->apply((array) json_decode($event->body, true));
            }
        } catch (Throwable $e) {
            $event->last_error = $e instanceof TelenowError ? "Telenow {$e->status}: {$e->getMessage()}" : $e->getMessage();
            $event->locked_until = null;
            if ($event->attempts < config('kit.events.max_attempts')) {
                $event->next_attempt_at = now()->addSeconds($this->wait($event->attempts));
            } else {
                $event->failed_at = now();
            }
            $event->save();

            return false;
        }
        $event->forceFill(['applied_at' => now(), 'locked_until' => null, 'last_error' => null])->save();

        return true;
    }

    private function wait(int $attempts): int
    {
        return (int) min(config('kit.events.retry_base_seconds') * (2 ** ($attempts - 1)), config('kit.events.retry_max_seconds'));
    }
}
