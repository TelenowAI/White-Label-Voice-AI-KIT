<?php

namespace App\Kit\Outbox;

use App\Kit\Telenow\TelenowError;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The kit's writes to Telenow (SELF_HOSTED_KIT_PLAN.md §3.8 a): a write is queued in the same transaction as the
 * kit row it changes (enqueue), sent at once for an interactive action (runFor), and retried by `kit:outbox`
 * (runDue) with the same Idempotency-Key until Telenow takes it or it is given up.
 *
 * - One row's writes go in order: only the oldest unfinished write of a row is ever sent, and one that was given up
 *   holds back the writes after it until it is retried (retry).
 * - A runner claims a write before sending it, so two runners (a request and the cron, or two crons) never send it
 *   twice at once.
 * - Telenow unreachable, 408, 425, 429 or 5xx: tried again after a growing wait. Any other refusal: given up at once
 *   with Telenow's reason. Either way after config('kit.outbox.max_attempts') tries.
 * - A handler that says its write's time has not come (NotYet): looked at again after
 *   config('kit.outbox.not_yet_seconds'). That is not a try, so waiting never gives a write up.
 *
 * Not here: what a write does (the handlers in config('kit.outbox.handlers')).
 */
final class Outbox
{
    public function __construct(private readonly Container $container) {}

    public function enqueue(string $resource, string $kitId, string $operation, ?array $body, ?string $idempotencyKey = null): OutboxEntry
    {
        return OutboxEntry::create([
            'resource' => $resource,
            'kit_id' => $kitId,
            'operation' => $operation,
            'body' => $body,
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
            'next_attempt_at' => now(),
        ]);
    }

    /** Send a row's due writes now, in order, stopping at the first that is not done. */
    public function runFor(string $resource, string $kitId): void
    {
        while (($entry = $this->head($resource, $kitId)) !== null && $entry->failed_at === null && $entry->next_attempt_at <= now()) {
            if (! $this->attempt($entry)) {
                return;
            }
        }
    }

    /** Send up to config('kit.outbox.batch') due writes, each row's in order. Answers how many were done. */
    public function runDue(): int
    {
        $done = 0;
        $heads = OutboxEntry::query()
            ->whereNull('done_at')->whereNull('failed_at')->where('next_attempt_at', '<=', now())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('telenow_outbox as before')
                ->whereColumn('before.resource', 'telenow_outbox.resource')
                ->whereColumn('before.kit_id', 'telenow_outbox.kit_id')
                ->whereColumn('before.id', '<', 'telenow_outbox.id')
                ->whereNull('before.done_at'))
            ->orderBy('next_attempt_at')->orderBy('id')
            ->limit(config('kit.outbox.batch'))
            ->get();
        foreach ($heads as $entry) {
            if ($this->attempt($entry)) {
                $done++;
                $this->runFor($entry->resource, $entry->kit_id);
            }
        }

        return $done;
    }

    /** Send a row's given-up writes again, from the first. */
    public function retry(string $resource, string $kitId): void
    {
        OutboxEntry::query()->where('resource', $resource)->where('kit_id', $kitId)
            ->whereNull('done_at')->whereNotNull('failed_at')
            ->update(['failed_at' => null, 'attempts' => 0, 'next_attempt_at' => now(), 'updated_at' => now()]);
        $this->runFor($resource, $kitId);
    }

    /** Whether one of the row's writes was given up. */
    public function hasFailed(string $resource, string $kitId, ?string $operation = null): bool
    {
        return OutboxEntry::query()->where('resource', $resource)->where('kit_id', $kitId)
            ->when($operation !== null, fn ($q) => $q->where('operation', $operation))
            ->whereNull('done_at')->whereNotNull('failed_at')->exists();
    }

    /** Whether one of the row's writes has yet to be done, whether waiting or given up. */
    public function hasUnfinished(string $resource, string $kitId): bool
    {
        return OutboxEntry::query()->where('resource', $resource)->where('kit_id', $kitId)->whereNull('done_at')->exists();
    }

    /** Close the row's unfinished writes, or only those of one operation, without sending them, saying why (`$reason` is kept as their last error). */
    public function cancel(string $resource, string $kitId, string $reason, ?string $operation = null): void
    {
        OutboxEntry::query()->where('resource', $resource)->where('kit_id', $kitId)->whereNull('done_at')
            ->when($operation !== null, fn ($q) => $q->where('operation', $operation))
            ->update(['done_at' => now(), 'failed_at' => null, 'last_error' => $reason, 'locked_until' => null, 'updated_at' => now()]);
    }

    /** The oldest write of the row that is not done (it may be given up or waiting). */
    private function head(string $resource, string $kitId): ?OutboxEntry
    {
        return OutboxEntry::query()->where('resource', $resource)->where('kit_id', $kitId)
            ->whereNull('done_at')->orderBy('id')->first();
    }

    /** One try. Answers true when Telenow took it. */
    private function attempt(OutboxEntry $entry): bool
    {
        $now = now();
        $claimed = OutboxEntry::query()->whereKey($entry->id)->whereNull('done_at')->whereNull('failed_at')
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->update(['locked_until' => $now->copy()->addSeconds(config('kit.outbox.lock_seconds')), 'updated_at' => $now]);
        if ($claimed !== 1) {
            return false; // another runner has it
        }
        $entry->refresh();
        // The map's keys hold a dot ('workspace.create'), so it is read whole: config() would take the dot as nesting.
        $class = config('kit.outbox.handlers')["{$entry->resource}.{$entry->operation}"] ?? null;
        if ($class === null) {
            throw new \LogicException("No outbox handler for {$entry->resource}.{$entry->operation}");
        }
        /** @var OutboxHandler $handler */
        $handler = $this->container->make($class);
        $entry->attempts++;
        try {
            $handler->send($entry);
        } catch (NotYet $e) {
            $entry->attempts--;
            $entry->last_error = $e->getMessage();
            $entry->locked_until = null;
            $entry->next_attempt_at = now()->addSeconds((int) config('kit.outbox.not_yet_seconds'));
            $entry->save();
            $handler->waiting($entry, new TelenowError(0, $e->getMessage()));

            return false;
        } catch (Throwable $e) {
            $error = $e instanceof TelenowError ? $e : new TelenowError(0, $e->getMessage());
            $entry->last_error = $error->getMessage();
            $entry->locked_until = null;
            if ($this->retryable($error) && $entry->attempts < config('kit.outbox.max_attempts')) {
                $entry->next_attempt_at = now()->addSeconds($this->wait($entry->attempts));
                $entry->save();
                $handler->waiting($entry, $error);
            } else {
                $entry->failed_at = now();
                $entry->save();
                $handler->failed($entry, $error);
            }

            return false;
        }
        $entry->done_at = now();
        $entry->last_error = null;
        $entry->locked_until = null;
        $entry->save();

        return true;
    }

    private function retryable(TelenowError $error): bool
    {
        return in_array($error->status, [0, 408, 425, 429], true) || $error->status >= 500;
    }

    private function wait(int $attempts): int
    {
        return (int) min(config('kit.outbox.retry_base_seconds') * (2 ** ($attempts - 1)), config('kit.outbox.retry_max_seconds'));
    }
}
