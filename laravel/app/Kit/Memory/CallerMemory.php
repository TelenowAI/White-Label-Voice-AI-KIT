<?php

namespace App\Kit\Memory;

use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Call;
use App\Models\ChatConversation;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What the account's agents remember about callers (contract/kit-api.yaml getCallerMemory, forgetCaller; console Caller memory). An agent set to
 * remember callers (App\Kit\Agents\AgentBehaviour) has Telenow keep, after each call, a short summary and some facts about the person, used on their
 * next call until it ends; read live with the account's own key, at most config('kit.memory.limit') of the most recent (recorded,
 * contract_k17memory.py), ended ones too, which are no longer used. A phone caller is named by their number; a web chat visitor (the kit sends its
 * conversation as `kit-<id>`, App\Kit\Chat\Conversations) by the website they chatted on; any other (an app's own identifier) as Telenow keeps it;
 * Telenow's own ids (its org, its session) never leave the kit, the session only as the kit's own call. Forgetting cannot be undone, so only someone who manages the account may; any caller, listed or not.
 *
 * Not here: the agent's switch and days (AgentBehaviour), what the memory costs (in the call's charge, App\Kit\Wallet\CallCharges).
 */
final class CallerMemory
{
    public function __construct(private readonly TelenowClient $telenow) {}

    /** @return array{callers: list<array<string, mixed>>, limit: int, canForget: bool} */
    public function list(WorkspaceMember $member): array
    {
        $workspace = $member->workspace;
        $rows = [];
        if ($workspace->telenow_sub_account_id !== null && $workspace->telenow_key !== null) {
            try {
                $rows = $this->telenow->callerMemory($workspace->telenow_key, $workspace->telenow_sub_account_id);
            } catch (TelenowError) {
                throw new MemoryRefused(503, __('memory.unavailable'));
            }
        }
        $sessions = array_values(array_filter(array_map(fn ($r) => $r['lastSession'] ?? null, $rows), 'is_string'));
        $calls = $sessions === [] ? [] : Call::query()->where('workspace_id', $workspace->id)->whereIn('telenow_id', $sessions)->pluck('id', 'telenow_id')->all();
        $prefix = config('kit.memory.chat_prefix');
        $conversations = array_values(array_filter(array_map(function ($r) use ($prefix) {
            $key = (string) ($r['callerKey'] ?? '');

            return str_starts_with($key, $prefix) && Str::isUuid(substr($key, strlen($prefix))) ? substr($key, strlen($prefix)) : null;
        }, $rows)));
        $websites = $conversations === [] ? [] : ChatConversation::query()->where('workspace_id', $workspace->id)->whereIn('id', $conversations)->pluck('website', 'id')->all();
        $callers = [];
        foreach ($rows as $row) {
            $key = (string) ($row['callerKey'] ?? '');
            if ($key === '') {
                continue;
            }
            $chat = str_starts_with($key, $prefix) && Str::isUuid(substr($key, strlen($prefix))) ? substr($key, strlen($prefix)) : null;
            $phone = $chat === null && preg_match('/^\d+$/', $key) === 1;
            $expires = self::instant($row['expiresAt'] ?? null);
            $callers[] = [
                'key' => $key,
                'kind' => $chat !== null ? 'chat' : ($phone ? 'phone' : 'other'),
                'caller' => $phone ? '+'.$key : $key,
                'website' => $chat !== null ? ($websites[$chat] ?? null) : null,
                'summary' => is_string($row['summary'] ?? null) ? $row['summary'] : null,
                'facts' => self::facts($row['facts'] ?? []),
                'calls' => (int) ($row['callCount'] ?? 0),
                'updatedAt' => self::instant($row['updatedAt'] ?? null) ?? now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'expiresAt' => $expires,
                'expired' => $expires !== null && CarbonImmutable::parse($expires)->isPast(),
                'lastCallId' => is_string($row['lastSession'] ?? null) ? ($calls[$row['lastSession']] ?? null) : null,
            ];
        }

        return ['callers' => $callers, 'limit' => config('kit.memory.limit'), 'canForget' => $this->manages($member)];
    }

    /**
     * Forget one caller: a phone number however it is written (only digits, spaces and `+ ( ) - .`: kept as its digits, as Telenow keeps it), or any
     * other listed caller's key as it is, lowercased as Telenow keeps one (recorded) — never cut down to its digits, which could name another caller.
     */
    public function forget(WorkspaceMember $member, array $input): bool
    {
        if (! $this->manages($member)) {
            throw new MemoryRefused(403, __('memory.only_managers'));
        }
        $given = trim((string) Validator::make($input, ['caller' => ['required', 'string', 'max:128']], [], __('memory.fields'))->validate()['caller']);
        $caller = preg_match('/^[0-9 +().\-]+$/', $given) === 1 ? Call::digits($given) : mb_strtolower($given);
        if ($caller === '' || $caller === null) {
            throw ValidationException::withMessages(['caller' => [__('memory.caller_invalid')]]);
        }
        $workspace = $member->workspace;
        if ($workspace->telenow_sub_account_id === null || $workspace->telenow_key === null) {
            return false;
        }
        try {
            return $this->telenow->forgetCaller($workspace->telenow_key, $workspace->telenow_sub_account_id, $caller);
        } catch (TelenowError $e) {
            if ($e->status === 400) {
                throw ValidationException::withMessages(['caller' => [__('memory.caller_invalid')]]);
            }
            throw new MemoryRefused(503, __('memory.unavailable'));
        }
    }

    private function manages(WorkspaceMember $member): bool
    {
        return in_array($member->role, config('kit.team.managers'), true);
    }

    /** @return list<array{name: string, value: string}> */
    private static function facts(mixed $facts): array
    {
        $list = [];
        foreach (is_array($facts) ? $facts : [] as $name => $value) {
            $list[] = ['name' => (string) $name, 'value' => is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : (string) json_encode($value)];
        }

        return $list;
    }

    private static function instant(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable) {
            return null;
        }
    }
}
