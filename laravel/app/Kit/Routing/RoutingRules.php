<?php

namespace App\Kit\Routing;

use App\Models\Agent;
use App\Models\PhoneNumber;
use App\Models\RoutingRule;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An account's rules for who takes a call to a routed number (console → Call routing; contract/kit-api.yaml createRoutingRule, updateRoutingRule,
 * deleteRoutingRule, orderRoutingRules), kept in the kit only: Telenow asks this kit for each call (RoutingConnection) and never sees them.
 *
 * A rule: a name; on or off; what it matches — numbers called (the account's own), lines that forwarded the call and starts of callers' numbers (at
 * most config('kit.routing.numbers_per_rule') each, E.164 / "+" and digits), ISO days and a from–until window with a timezone (a window past
 * midnight is one); what it does — `answer` (with one of the account's agents, or the number's own; a language, a greeting and a cap in minutes
 * if set, each within what Telenow takes) or `refuse`. A refusal keeps none of an answer's settings. New rules go last; the order is the
 * account's to set.
 *
 * Not here: which rule takes a call (RoutingMatch), who may change them (the controller: the account's managers).
 */
final class RoutingRules
{
    public const DAYS = [1, 2, 3, 4, 5, 6, 7];

    /** @return list<RoutingRule> the account's, in order */
    public function list(Workspace $workspace): array
    {
        return RoutingRule::query()->with('agent')->where('workspace_id', $workspace->id)->orderBy('position')->orderBy('created_at')->get()->all();
    }

    public function create(Workspace $workspace, array $input): RoutingRule
    {
        $max = (int) config('kit.routing.rules_max');
        if (RoutingRule::query()->where('workspace_id', $workspace->id)->count() >= $max) {
            throw new RoutingRefused(409, __('routing.too_many_rules', ['max' => $max]));
        }
        $rule = new RoutingRule;
        $rule->forceFill($this->checked($workspace, $input, null) + [
            'workspace_id' => $workspace->id,
            'position' => (int) RoutingRule::query()->where('workspace_id', $workspace->id)->max('position') + 1,
        ])->save();

        return $rule->load('agent');
    }

    public function update(RoutingRule $rule, array $input): RoutingRule
    {
        $rule->forceFill($this->checked(Workspace::query()->findOrFail($rule->workspace_id), $input, $rule))->save();

        return $rule->load('agent');
    }

    public function delete(RoutingRule $rule): void
    {
        $rule->delete();
    }

    /**
     * The account's rules in this order: every one of its rules, each once.
     *
     * @param  list<string>  $ids
     */
    public function order(Workspace $workspace, array $ids): void
    {
        $have = RoutingRule::query()->where('workspace_id', $workspace->id)->pluck('id')->all();
        if (count($ids) !== count(array_unique($ids)) || array_diff($have, $ids) !== [] || array_diff($ids, $have) !== []) {
            throw ValidationException::withMessages(['ids' => [__('routing.order_whole')]]);
        }
        DB::transaction(function () use ($workspace, $ids) {
            foreach (array_values($ids) as $i => $id) {
                RoutingRule::query()->where('workspace_id', $workspace->id)->where('id', $id)->update(['position' => $i + 1]);
            }
        });
    }

    /** The rule's columns from what the console sent, checked; on an update, what was not sent stays. */
    private function checked(Workspace $workspace, array $input, ?RoutingRule $rule): array
    {
        $c = config('kit.routing');
        $phone = config('kit.phone.pattern');
        $creating = $rule === null;
        $required = $creating ? 'required' : 'sometimes';
        $data = Validator::make($input, [
            'name' => [$required, 'string', 'max:'.$c['name_max_length']],
            'enabled' => ['sometimes', 'boolean'],
            'numberIds' => ['sometimes', 'array', 'max:'.$c['numbers_per_rule']],
            'numberIds.*' => ['string', 'distinct'],
            'forwardedFrom' => ['sometimes', 'array', 'max:'.$c['numbers_per_rule']],
            'forwardedFrom.*' => ['string', 'distinct', 'regex:'.$phone],
            'callerStartsWith' => ['sometimes', 'array', 'max:'.$c['numbers_per_rule']],
            'callerStartsWith.*' => ['string', 'distinct', 'regex:/^\+[1-9][0-9]{'.max(0, $c['caller_start_min_digits'] - 1).',14}$/'],
            'days' => ['sometimes', 'array'],
            'days.*' => ['integer', 'distinct', Rule::in(self::DAYS)],
            'from' => ['sometimes', 'nullable', 'date_format:H:i'],
            'until' => ['sometimes', 'nullable', 'date_format:H:i'],
            // Older names too (Asia/Calcutta): what a browser reports as its own timezone (found in the browser check).
            'timezone' => ['sometimes', 'nullable', 'timezone:all_with_bc'],
            'action' => [$required, Rule::in(['answer', 'refuse'])],
            'agentId' => ['sometimes', 'nullable', 'string'],
            'language' => ['sometimes', 'nullable', 'string', 'max:'.$c['language_max_length'], 'regex:'.$c['language_pattern']],
            'greeting' => ['sometimes', 'nullable', 'string', 'max:'.$c['greeting_max_length']],
            'maxMinutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.$c['max_minutes']],
        ], [
            'forwardedFrom.*.regex' => __('routing.phone_shape'),
            'callerStartsWith.*.regex' => __('routing.start_shape'),
            'language.regex' => __('routing.language_shape'),
        ], __('routing.fields'))->validate();

        $out = [];
        foreach (['name' => 'name', 'enabled' => 'enabled', 'from' => 'from_time', 'until' => 'until_time', 'timezone' => 'timezone', 'action' => 'action',
            'language' => 'language', 'greeting' => 'greeting', 'maxMinutes' => 'max_minutes'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $out[$column] = is_string($data[$field]) && $column !== 'name' && trim($data[$field]) === '' ? null : $data[$field];
            }
        }
        foreach (['forwardedFrom' => 'forwarded_from', 'callerStartsWith' => 'caller_starts'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $out[$column] = array_values($data[$field]);
            }
        }
        if (array_key_exists('days', $data)) {
            $out['days'] = array_values(array_map('intval', $data['days']));
            sort($out['days']);
        }
        if (array_key_exists('numberIds', $data)) {
            // The account's numbers that take calls (bought, not released), and any the rule has already: one released since stays until taken off.
            $ids = array_values($data['numberIds']);
            $kept = (array) ($rule?->numbers ?? []);
            $held = PhoneNumber::query()->where('workspace_id', $workspace->id)->whereIn('id', $ids)
                ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('released_at')->whereNotNull('telenow_id'))->orWhereIn('id', $kept))->pluck('id')->all();
            if (count($held) !== count($ids)) {
                throw ValidationException::withMessages(['numberIds' => [__('routing.unknown_number')]]);
            }
            $out['numbers'] = $ids;
        }
        if (array_key_exists('agentId', $data)) {
            $out['agent_id'] = null;
            if ($data['agentId'] !== null) {
                $agent = Agent::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')->whereNotNull('telenow_id')->find($data['agentId']);
                if ($agent === null) {
                    throw ValidationException::withMessages(['agentId' => [__('routing.unknown_agent')]]);
                }
                $out['agent_id'] = $agent->id;
            }
        }
        if ($creating) {
            $out += ['enabled' => true, 'numbers' => [], 'forwarded_from' => [], 'caller_starts' => [], 'days' => []];
        }

        // As the rule will be: a window has both ends, unequal, and a timezone; days need a timezone too.
        $will = $out + ($rule?->only(['from_time', 'until_time', 'timezone', 'days', 'action']) ?? []);
        $from = $will['from_time'] ?? null;
        $until = $will['until_time'] ?? null;
        if (($from === null) !== ($until === null)) {
            throw ValidationException::withMessages([$from === null ? 'from' : 'until' => [__('routing.window_both')]]);
        }
        if ($from !== null && $from === $until) {
            throw ValidationException::withMessages(['until' => [__('routing.window_empty')]]);
        }
        if (($from !== null || ($will['days'] ?? []) !== []) && ($will['timezone'] ?? null) === null) {
            throw ValidationException::withMessages(['timezone' => [__('routing.timezone_needed')]]);
        }
        if (($will['action'] ?? null) === 'refuse') {
            $out = array_merge($out, ['agent_id' => null, 'language' => null, 'greeting' => null, 'max_minutes' => null]);
        }

        return $out;
    }
}
