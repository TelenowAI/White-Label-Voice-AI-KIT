<?php

namespace App\Kit\Calls;

use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Call health (contract/kit-api.yaml getCallHealth, getCustomerCallHealth; getVoicePlatformHealth's `callHealth`; owner's choice 11-09: customers
 * and staff, staff in detail): which speech recognition, voice and language model providers failed on an account's recent calls, how many calls
 * were affected and on which agents — Telenow's own record of each failure (`getProviderHealth`, read with the account's own key: an operator's key
 * reads its own account only, recorded).
 *
 * - account(): one account's failures in the last `minutes` (1 to 1440), each provider under the operator's name for it (Admin → Catalog; a name
 *   that carries the platform's, or a provider the catalog has not got, is said by its part: "the voice provider") and the cause in the kit's words
 *   (`provider`, `setup` — the agent's own setup or account, `platform`, `unknown`), with the account's agents. Customers never get the raw error,
 *   Telenow's provider id or its key source; staff get them (`staff`). Kept config('kit.call_health.cache_seconds').
 * - sweep(): `kit:call-health`, every config('kit.call_health.sweep_minutes'): the accounts that had calls in the window (at most
 *   config('kit.call_health.sweep_max_accounts'), the busiest first), each read, added up by provider — calls and customers affected — for Admin →
 *   Voice platform and the dashboard (a provider with config('kit.call_health.alert_calls') calls or more is flagged).
 *
 * Not here: a call's own failure (the call's page); the provider check of an agent's setup (App\Kit\Agents\StackCheck).
 */
final class CallHealth
{
    public const SUMMARY = 'call_health.summary';

    private const CAUSES = ['provider_outage' => 'provider', 'customer_config' => 'setup', 'customer_account' => 'setup', 'platform' => 'platform'];

    /** @var array<string, list<array>> the catalog's providers by part, read once per read (or per sweep: never kept from one request to the next) */
    private array $providers = [];

    private bool $sweeping = false;

    public function __construct(private readonly TelenowClient $telenow, private readonly Catalog $catalog, private readonly Settings $settings) {}

    /** The window asked for, checked: minutes from 1 to the most Telenow reads. */
    public static function minutes(mixed $minutes): int
    {
        if ($minutes === null || $minutes === '') {
            return (int) config('kit.call_health.minutes');
        }
        if (filter_var($minutes, FILTER_VALIDATE_INT) === false || (int) $minutes < 1 || (int) $minutes > (int) config('kit.call_health.max_minutes')) {
            throw ValidationException::withMessages(['minutes' => [__('call_health.minutes', ['max' => config('kit.call_health.max_minutes')])]]);
        }

        return (int) $minutes;
    }

    /** @return array{windowMinutes: int, callsAffected: int, checkedAt: string, failures: list<array>} */
    public function account(Workspace $workspace, int $minutes, bool $staff = false): array
    {
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '') {
            throw new CallHealthRefused(409, __('call_health.no_account'));
        }
        if (! $this->sweeping) {
            $this->providers = [];
        }
        $read = Cache::get($cache = "kit.call-health.{$workspace->id}.{$minutes}");
        if (! is_array($read)) {
            try {
                $read = ['answer' => $this->telenow->providerHealth($workspace->telenow_key, $minutes), 'at' => now()->toIso8601String()];
            } catch (TelenowError) {
                throw new CallHealthRefused(502, __('call_health.unread'));
            }
            Cache::put($cache, $read, now()->addSeconds((int) config('kit.call_health.cache_seconds')));
        }
        $answer = $read['answer'];
        $groups = array_values(array_filter((array) ($answer['groups'] ?? []), 'is_array'));
        $ids = array_values(array_unique(array_merge(...array_map(fn (array $g) => array_filter((array) ($g['agentIds'] ?? []), 'is_string'), $groups ?: [[]]))));
        $agents = $ids === [] ? collect() : Agent::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')->whereIn('telenow_id', $ids)
            ->get(['id', 'name', 'telenow_id'])->keyBy('telenow_id');

        return [
            'windowMinutes' => (int) ($answer['windowMinutes'] ?? $minutes),
            'callsAffected' => (int) ($answer['callsAffected'] ?? 0),
            'checkedAt' => $read['at'],
            'failures' => array_map(function (array $g) use ($agents, $staff) {
                $part = in_array($g['component'] ?? null, array_keys(config('kit.catalog.kinds')), true) ? $g['component'] : 'other';
                $row = [
                    'part' => $part,
                    'provider' => $this->providerName($part, (string) ($g['provider'] ?? '')),
                    'cause' => self::CAUSES[$g['attribution'] ?? ''] ?? 'unknown',
                    'calls' => (int) ($g['calls'] ?? 0),
                    'lastAt' => is_string($g['lastAt'] ?? null) ? $g['lastAt'] : null,
                    'agents' => array_values(array_map(fn ($a) => ['id' => $a->id, 'name' => $a->name],
                        array_filter(array_map(fn ($id) => is_string($id) ? $agents->get($id) : null, (array) ($g['agentIds'] ?? []))))),
                ];

                return $staff ? $row + [
                    'providerId' => (string) ($g['provider'] ?? ''),
                    'attribution' => (string) ($g['attribution'] ?? ''),
                    'keySource' => (string) ($g['keySource'] ?? ''),
                    'incidents' => (int) ($g['incidents'] ?? 0),
                    'error' => is_string($g['lastError'] ?? null) ? Str::limit($g['lastError'], (int) config('kit.health.error_length'), '…') : null,
                ] : $row;
            }, $groups),
        ];
    }

    /** Read every account with calls in the window and add the failures up by provider, for Admin → Voice platform and the dashboard. */
    public function sweep(): array
    {
        $this->sweeping = true;
        $this->providers = [];
        try {
            return $this->sweepNow();
        } finally {
            $this->sweeping = false;
            $this->providers = [];
        }
    }

    private function sweepNow(): array
    {
        $minutes = (int) config('kit.call_health.minutes');
        $since = now()->subMinutes($minutes);
        $active = fn () => Workspace::query()->whereNotNull('telenow_key')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('calls')->whereColumn('calls.workspace_id', 'workspaces.id')->where('calls.started_at', '>=', $since));
        $accounts = $active()->select('workspaces.*')
            ->selectSub(DB::table('calls')->selectRaw('count(*)')->whereColumn('calls.workspace_id', 'workspaces.id')->where('calls.started_at', '>=', $since), 'recent_calls')
            ->orderByDesc('recent_calls')->orderBy('id')->limit((int) config('kit.call_health.sweep_max_accounts'))->get();
        $providers = [];
        $unread = 0;
        foreach ($accounts as $workspace) {
            try {
                $health = $this->account($workspace, $minutes, staff: true);
            } catch (CallHealthRefused) {
                $unread++;

                continue;
            }
            foreach ($health['failures'] as $f) {
                $key = $f['part'].'|'.$f['providerId'];
                $p = $providers[$key] ?? ['part' => $f['part'], 'providerId' => $f['providerId'], 'provider' => $f['provider'], 'calls' => 0, 'customers' => [], 'lastAt' => null];
                $p['calls'] += $f['calls'];
                $p['customers'][$workspace->id] = ['id' => $workspace->id, 'name' => $workspace->name, 'calls' => ($p['customers'][$workspace->id]['calls'] ?? 0) + $f['calls']];
                $p['lastAt'] = max((string) $p['lastAt'], (string) $f['lastAt']) ?: null;
                $providers[$key] = $p;
            }
        }
        $list = array_map(function (array $p) {
            $customers = array_values($p['customers']);
            usort($customers, fn ($a, $b) => $b['calls'] <=> $a['calls'] ?: strcmp($a['name'], $b['name']));

            return ['customerCount' => count($customers), 'customers' => array_slice($customers, 0, (int) config('kit.call_health.customers_listed'))] + $p;
        }, array_values($providers));
        usort($list, fn ($a, $b) => $b['calls'] <=> $a['calls'] ?: strcmp($a['part'].$a['providerId'], $b['part'].$b['providerId']));
        $summary = ['checkedAt' => now()->toIso8601String(), 'windowMinutes' => $minutes, 'accountsActive' => $active()->count(), 'accountsChecked' => $accounts->count() - $unread,
            'accountsUnread' => $unread, 'alertCalls' => (int) config('kit.call_health.alert_calls'), 'providers' => $list];
        $this->settings->put(self::SUMMARY, $summary);

        return $summary;
    }

    /** The sweep's last summary, or null before the first; its alert level the one set now, as the dashboard flags by it (failing()). */
    public function summary(): ?array
    {
        $s = $this->settings->get(self::SUMMARY);

        return is_array($s) ? ['alertCalls' => (int) config('kit.call_health.alert_calls')] + $s : null;
    }

    /** How many providers failed on enough calls in the last sweep to be flagged. */
    public function failing(): int
    {
        return count(array_filter($this->summary()['providers'] ?? [], fn (array $p) => $p['calls'] >= (int) config('kit.call_health.alert_calls')));
    }

    /** A provider as customers know it: the operator's name for it, or, when that would name the platform or there is none, its part. */
    private function providerName(string $part, string $id): string
    {
        if ($part !== 'other') {
            foreach ($this->providers[$part] ??= $this->catalog->providers($part) as $p) {
                if ($p['id'] === $id && ! $p['namesPlatform']) {
                    return $p['displayName'];
                }
            }
        }

        return __("call_health.parts.{$part}");
    }
}
