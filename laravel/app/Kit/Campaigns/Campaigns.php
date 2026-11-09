<?php

namespace App\Kit\Campaigns;

use App\Kit\Campaigns\Telenow\CampaignWrite;
use App\Kit\Console\ServicePause;
use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\Outbox;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\PhoneNumber;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A customer's campaigns (console → Campaigns; contract/resources.yaml `campaigns`, `campaign_targets`; SELF_HOSTED_KIT_PLAN.md
 * §3.8, §4.4), written to the kit's DB and the outbox in one transaction, then sent to Telenow at once with the customer's
 * own key:
 *
 * - a campaign is made (its agent, caller id, calling window, attempts, voicemail handling) as a draft;
 * - people to call come from a CSV (a header row; the number in one of config('kit.campaigns.phone_columns'), every other
 *   column a variable), each kept with the kit's own id, which travels to Telenow and back on the call's result;
 * - it is started (never while the service is paused for the account, never with nobody to call), paused, cancelled,
 *   deleted;
 * - its settings can be changed after it is made (update), through the outbox: Telenow clamps what is out of range without saying and lets a cancelled campaign be changed, so both are refused here;
 * - refresh() reads it again (Telenow sends no campaign events) and follows the results feed: how each call went.
 *
 * Not here: sending (Telenow/*), the pages (CampaignsController), trying people again (CampaignRetries), how each person reads (CampaignPeople), how it went (CampaignProgress).
 */
final class Campaigns
{
    private const SECONDS_PER_MINUTE = 60;

    public function __construct(
        private readonly Outbox $outbox,
        private readonly Manifest $manifest,
        private readonly TelenowClient $telenow,
        private readonly ServicePause $pause,
        private readonly CampaignRetries $retries,
    ) {}

    public function create(Workspace $workspace, array $input): Campaign
    {
        if ($workspace->telenow_sub_account_id === null) {
            throw new CampaignRefused(409, __('campaigns.account_not_ready'));
        }
        $c = config('kit.campaigns');
        $values = $this->check($input, [
            'name' => ['required', 'string', 'max:'.$c['name_max_length']],
            'agentId' => ['required', 'string'],
        ] + $this->settingRules());
        $agent = Str::isUuid($values['agentId']) ? Agent::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')->find($values['agentId']) : null;
        $caller = isset($values['callerId']) && Str::isUuid($values['callerId'])
            ? PhoneNumber::query()->where('workspace_id', $workspace->id)->whereNull('released_at')->find($values['callerId']) : null;
        $errors = array_filter([
            'agentId' => $agent === null ? [__('campaigns.unknown_agent')] : null,
            'callerId' => isset($values['callerId']) && $caller === null ? [__('campaigns.unknown_caller_id')] : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $columns = [
            'name' => $values['name'],
            'concurrency' => $values['concurrency'] ?? $c['concurrency']['default'],
            'start_time_local' => $values['windowStart'] ?? null,
            'end_time_local' => $values['windowEnd'] ?? null,
            'timezone' => $values['timezone'] ?? config('app.timezone'),
            'max_attempts' => $values['maxAttempts'] ?? $c['max_attempts']['default'],
            'retry_on_no_answer' => (bool) ($values['retryOnNoAnswer'] ?? true),
            'retry_backoff_secs' => (int) ($values['retryGapMinutes'] ?? $c['retry_gap_minutes']['default']) * self::SECONDS_PER_MINUTE,
        ];
        $campaign = DB::transaction(function () use ($workspace, $agent, $caller, $columns, $values, $c) {
            $campaign = (new Campaign)->forceFill($columns + [
                'workspace_id' => $workspace->id, 'agent_id' => $agent->id, 'phone_number_id' => $caller?->id,
                'machine_detection' => $values['machineDetection'] ?? $c['machine_detection'][0],
            ]);
            $campaign->save();
            $this->outbox->enqueue(CampaignWrite::RESOURCE, $campaign->id, 'create',
                array_filter($this->manifest->writeBody(CampaignWrite::RESOURCE, 'create', $columns), fn ($v) => $v !== null));

            return $campaign;
        });
        $this->outbox->runFor(CampaignWrite::RESOURCE, $campaign->id);

        return $campaign->fresh();
    }

    /**
     * Change what `$input` names; the rest stays. A window goes as both of its ends, and a caller id or a window of null clears it. Telenow's refusal leaves the campaign as it was.
     */
    public function update(Campaign $campaign, array $input): Campaign
    {
        if (! is_string($campaign->telenow_id)) {
            throw new CampaignRefused(409, __('campaigns.not_ready'));
        }
        if ($campaign->status === 'cancelled') {
            throw new CampaignRefused(409, __('campaigns.cancelled_no_change'));
        }
        if ($campaign->sync_state === 'pending') {
            throw new CampaignRefused(409, __('campaigns.wait'));
        }
        if (array_key_exists('windowStart', $input) || array_key_exists('windowEnd', $input)) {
            $input += ['windowStart' => null, 'windowEnd' => null];
        }
        $values = $this->check($input, array_intersect_key($this->settingRules(), $input));
        $columns = [];
        foreach (['concurrency' => 'concurrency', 'timezone' => 'timezone', 'machineDetection' => 'machine_detection', 'maxAttempts' => 'max_attempts', 'retryOnNoAnswer' => 'retry_on_no_answer'] as $field => $column) {
            if (isset($values[$field])) {
                $columns[$column] = $values[$field];
            }
        }
        if (isset($values['retryOnNoAnswer'])) {
            $columns['retry_on_no_answer'] = (bool) $values['retryOnNoAnswer'];
        }
        if (isset($values['retryGapMinutes'])) {
            $columns['retry_backoff_secs'] = (int) $values['retryGapMinutes'] * self::SECONDS_PER_MINUTE;
        }
        if (array_key_exists('windowStart', $values)) {
            $columns += ['start_time_local' => $values['windowStart'], 'end_time_local' => $values['windowEnd']];
        }
        if (array_key_exists('callerId', $values)) {
            $caller = $values['callerId'] !== null && Str::isUuid($values['callerId'])
                ? PhoneNumber::query()->where('workspace_id', $campaign->workspace_id)->whereNull('released_at')->find($values['callerId']) : null;
            if ($values['callerId'] !== null && $caller === null) {
                throw ValidationException::withMessages(['callerId' => [__('campaigns.unknown_caller_id')]]);
            }
            $columns['phone_number_id'] = $caller?->id;
        }
        $changed = array_filter($columns, fn ($value, $column) => $value != $campaign->getAttribute($column), ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return $campaign;
        }
        $before = array_intersect_key($campaign->getAttributes(), $changed);
        DB::transaction(function () use ($campaign, $changed) {
            $campaign->forceFill($changed + ['sync_state' => 'pending', 'sync_error' => null])->save();
            $this->outbox->enqueue(CampaignWrite::RESOURCE, $campaign->id, 'settings', null);
        });
        $this->send($campaign);
        if ($this->outbox->hasFailed(CampaignWrite::RESOURCE, $campaign->id, 'settings')) {
            $this->outbox->cancel(CampaignWrite::RESOURCE, $campaign->id, __('campaigns.settings_refused'), 'settings');
            $campaign->refresh()->forceFill($before + ['sync_state' => 'synced', 'sync_error' => null])->save();
            throw new CampaignRefused(409, __('campaigns.settings_refused'));
        }

        return $campaign->fresh();
    }

    /** @return array{added: int, skipped: int, noCountryCode: int} people taken from the CSV, lines with no number, and those added with no + and country code, which consent on file is never matched to (recorded) */
    public function addTargets(Campaign $campaign, array $input): array
    {
        $c = config('kit.campaigns');
        $values = $this->check($input, ['csv' => ['required', 'string', 'max:'.$c['import_max_bytes']]]);
        if (in_array($campaign->status, $c['finished_statuses'], true)) {
            throw new CampaignRefused(409, __('campaigns.finished'));
        }
        [$rows, $skipped] = $this->parse($values['csv'], 'csv');
        $this->insert($campaign, $rows);

        return ['added' => count($rows), 'skipped' => $skipped, 'noCountryCode' => count(array_filter($rows, fn (array $r) => ! str_starts_with($r['phone'], '+')))];
    }

    /**
     * A call list as CSV, read as addTargets reads it: a header row, the number in one of config('kit.campaigns.phone_columns'), every other column a
     * variable; refused (at `$field`) with no number column or too many people.
     *
     * @return array{0: list<array{phone: string, variables: array<string, string>}>, 1: int} the people, and the lines with no number
     */
    public function parse(string $csv, string $field): array
    {
        $c = config('kit.campaigns');
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv((string) array_shift($lines), escape: '\\'));
        $phoneAt = null;
        foreach ($c['phone_columns'] as $name) {
            if (($at = array_search($name, $header, true)) !== false) {
                $phoneAt = $at;
                break;
            }
        }
        if ($phoneAt === null) {
            throw ValidationException::withMessages([$field => [__('campaigns.no_phone_column', ['columns' => implode(', ', $c['phone_columns'])])]]);
        }
        [$rows, $skipped] = [[], 0];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, escape: '\\');
            $phone = trim((string) ($cells[$phoneAt] ?? ''));
            if ($phone === '') {
                $skipped++;

                continue;
            }
            $variables = [];
            foreach ($header as $i => $name) {
                $value = trim((string) ($cells[$i] ?? ''));
                if ($i !== $phoneAt && $name !== '' && $value !== '') {
                    $variables[$name] = $value;
                }
            }
            $rows[] = ['phone' => $phone, 'variables' => $variables];
        }
        if (count($rows) > $c['import_max_rows']) {
            throw ValidationException::withMessages([$field => [__('campaigns.too_many_rows', ['max' => $c['import_max_rows']])]]);
        }

        return [$rows, $skipped];
    }

    /** People added to the campaign, and sent to Telenow with the next push (the outbox), as addTargets adds them. */
    public function insert(Campaign $campaign, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $c = config('kit.campaigns');
        DB::transaction(function () use ($campaign, $rows, $c) {
            $now = now();
            foreach (array_chunk($rows, $c['insert_batch']) as $chunk) {
                CampaignTarget::query()->insert(array_map(fn (array $r) => [
                    'id' => (string) Str::uuid(), 'campaign_id' => $campaign->id, 'workspace_id' => $campaign->workspace_id,
                    'phone_number' => $r['phone'], 'variables' => $r['variables'] === [] ? null : json_encode($r['variables']),
                    'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
            $this->outbox->enqueue(CampaignWrite::RESOURCE, $campaign->id, 'targets', null);
        });
        $this->send($campaign);
    }

    public function start(Campaign $campaign): Campaign
    {
        $paused = $this->pause->for($campaign->workspace);
        if ($paused !== null) {
            throw new CampaignRefused(409, $paused['message']);
        }
        if (! in_array($campaign->status, ['draft', 'paused'], true)) {
            throw new CampaignRefused(409, __('campaigns.cannot_start'));
        }
        if (! $campaign->targets()->exists()) {
            throw new CampaignRefused(409, __('campaigns.no_targets'));
        }

        return $this->act($campaign, 'start');
    }

    public function pause(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, ['queued', 'running'], true)) {
            throw new CampaignRefused(409, __('campaigns.cannot_pause'));
        }

        return $this->act($campaign, 'pause');
    }

    public function cancel(Campaign $campaign): Campaign
    {
        if (in_array($campaign->status, config('kit.campaigns.finished_statuses'), true)) {
            throw new CampaignRefused(409, __('campaigns.finished'));
        }

        return $this->act($campaign, 'cancel');
    }

    public function delete(Campaign $campaign): void
    {
        if ($campaign->telenow_id === null && $this->outbox->hasFailed(CampaignWrite::RESOURCE, $campaign->id)) {
            $this->outbox->cancel(CampaignWrite::RESOURCE, $campaign->id, __('campaigns.cancelled'));
            $campaign->forceFill(['deleted_at' => now(), 'sync_state' => 'synced', 'sync_error' => null])->save();

            return;
        }
        DB::transaction(function () use ($campaign) {
            $campaign->forceFill(['deleted_at' => now(), 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(CampaignWrite::RESOURCE, $campaign->id, 'delete', null);
        });
        $this->outbox->runFor(CampaignWrite::RESOURCE, $campaign->id);
    }

    /** Read the campaign again and the results Telenow has for it since the last read. Answers how many results it saw. */
    public function refresh(Campaign $campaign): int
    {
        [$key, $org] = CampaignWrite::account($campaign->workspace);
        $object = $this->telenow->campaign($key, $org, $campaign->telenow_id);
        if ($campaign->sync_state !== 'pending') { // the kit is changing it: its own write's answer comes first
            $campaign->forceFill($this->manifest->columns(CampaignWrite::RESOURCE, $object) + ['sync_state' => 'synced', 'sync_error' => null])->save();
        }
        $seen = 0;
        $tryingAgain = CampaignTarget::query()->where('campaign_id', $campaign->id)->whereNotNull('retried_at')->exists();
        for ($page = 0; $page < config('kit.campaigns.results_pages_per_run'); $page++) {
            $position = $campaign->results_cursor !== null ? ['cursor' => $campaign->results_cursor]
                : ['since' => $campaign->created_at->utc()->format('Y-m-d\TH:i:s.v\Z')];
            $answer = $this->telenow->campaignResults($key, $campaign->telenow_id, $position);
            foreach ($answer['results'] ?? [] as $r) {
                if (is_string($r['id'] ?? null) && Str::isUuid($r['id'])) {
                    $seen += CampaignTarget::query()->where('campaign_id', $campaign->id)->whereKey($r['id'])->update([
                        'outcome' => $r['outcome'] ?? null, 'attempt' => $r['attempt'] ?? null,
                        'completed_at' => is_string($r['completedAt'] ?? null) ? Carbon::parse($r['completedAt']) : null,
                        'telenow_call_id' => $r['callId'] ?? null, 'updated_at' => now(),
                    ]);
                    if ($tryingAgain && is_string($r['completedAt'] ?? null)) {
                        $this->retries->called($campaign, $r['id'], Carbon::parse($r['completedAt']));
                    }
                }
            }
            if (is_string($answer['nextCursor'] ?? null)) {
                $campaign->forceFill(['results_cursor' => $answer['nextCursor']])->save();
            }
            if (($answer['results'] ?? []) === [] || ! is_string($answer['nextCursor'] ?? null)) {
                break;
            }
        }
        $campaign->forceFill(['read_at' => now()])->save();

        return $seen;
    }

    /** @return array{pending: int, dialable: int, onDoNotCall: int, withoutConsent: int, blocked: bool} how many can be called now */
    public function reach(Campaign $campaign): array
    {
        $data = $this->live($campaign, fn (string $key, string $org, string $id) => $this->telenow->campaignReach($key, $org, $id));

        return ['pending' => (int) ($data['pending'] ?? 0), 'dialable' => (int) ($data['dialable'] ?? 0), 'onDoNotCall' => (int) ($data['excludedDnc'] ?? 0),
            'withoutConsent' => (int) ($data['excludedConsent'] ?? 0), 'blocked' => ($data['accountBlock'] ?? null) !== null];
    }

    /** @return list<array{name: string, required: bool}> the variables its agent uses (the columns a call list should carry) */
    public function variables(Campaign $campaign): array
    {
        $list = $this->live($campaign, fn (string $key, string $org, string $id) => $this->telenow->campaignVariables($key, $org, $id));

        return array_values(array_map(fn (array $v) => ['name' => (string) $v['name'], 'required' => (bool) ($v['required'] ?? false)],
            array_filter($list, fn ($v) => is_array($v) && is_string($v['name'] ?? null))));
    }

    private function live(Campaign $campaign, \Closure $read): array
    {
        if (! is_string($campaign->telenow_id)) {
            throw new CampaignRefused(409, __('campaigns.not_ready'));
        }
        try {
            [$key, $org] = CampaignWrite::account($campaign->workspace);

            return $read($key, $org, $campaign->telenow_id);
        } catch (TelenowError) {
            throw new CampaignRefused(503, __('campaigns.unavailable'));
        }
    }

    private function act(Campaign $campaign, string $operation): Campaign
    {
        DB::transaction(function () use ($campaign, $operation) {
            $campaign->forceFill(['sync_state' => 'pending'])->save();
            $this->outbox->enqueue(CampaignWrite::RESOURCE, $campaign->id, $operation, null);
        });
        $this->send($campaign);

        return $campaign->fresh();
    }

    /** One given up holds back those after it (Outbox), and a campaign has no other way to ask again: a new action does. */
    private function send(Campaign $campaign): void
    {
        if ($this->outbox->hasFailed(CampaignWrite::RESOURCE, $campaign->id)) {
            $this->outbox->retry(CampaignWrite::RESOURCE, $campaign->id);

            return;
        }
        $this->outbox->runFor(CampaignWrite::RESOURCE, $campaign->id);
    }

    /** @return array<string, list<string>> what can be set when a campaign is made and changed after, within what Telenow holds them to */
    private function settingRules(): array
    {
        $c = config('kit.campaigns');

        return [
            'callerId' => ['nullable', 'string'],
            'concurrency' => ['nullable', 'integer', 'min:1', 'max:'.$c['concurrency']['max']],
            'windowStart' => ['nullable', 'date_format:H:i', 'required_with:windowEnd', 'different:windowEnd'],
            'windowEnd' => ['nullable', 'date_format:H:i', 'required_with:windowStart'],
            // Every zone name, the older ones too: a browser may give one (Chrome: Asia/Calcutta), and Telenow takes any
            // name its Postgres knows.
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'machineDetection' => ['nullable', 'in:'.implode(',', $c['machine_detection'])],
            'maxAttempts' => ['nullable', 'integer', 'min:1', 'max:'.$c['max_attempts']['max']],
            'retryGapMinutes' => ['nullable', 'integer', 'min:'.$c['retry_gap_minutes']['min'], 'max:'.$c['retry_gap_minutes']['max']],
            'retryOnNoAnswer' => ['nullable', 'boolean'],
        ];
    }

    private function check(array $input, array $rules): array
    {
        $names = array_map(fn (string $f) => __("campaigns.fields.{$f}"), array_combine(array_keys($rules), array_keys($rules)));

        return Validator::make($input, $rules, [], $names)->validate();
    }
}
