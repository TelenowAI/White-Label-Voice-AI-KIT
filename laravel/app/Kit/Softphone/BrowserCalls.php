<?php

namespace App\Kit\Softphone;

use App\Kit\Agents\AgentSettings;
use App\Kit\Calls\DialCheck;
use App\Kit\Consent\ConsentRule;
use App\Kit\Console\ServicePause;
use App\Kit\Pricing\Pricing;
use App\Kit\Pricing\PricingEngine;
use App\Kit\Pricing\PricingRefused;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Call;
use App\Models\PhoneNumber as Number;
use App\Models\PriceList;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Calls a member places from the browser, from one of the account's own numbers (Console → Phone). The kit's server starts the call with the account's own key
 * (Telenow's `manual` mode, recorded: contract_k9human.py) and answers the console only what it joins with, as a test call does; no credential reaches the browser.
 *
 * None is placed until the operator allows them (Admin → Catalog, allowed()), as transfers are, and none while the service is paused for the account. Telenow dials
 * whatever it is given, words included, so the kit sends only a number with its + and country code. It also says no before Telenow would: a number on the account's
 * do-not-call list as the kit holds it, one with no consent on file while calls need it, and — while customers are charged — a plan with no price for such a call, which
 * would otherwise go uncharged. Telenow checks the first two again, by its own lists.
 *
 * The call is kept at once (App\Models\Call, with who placed it) and read like any other once it ends (App\Kit\Calls\Calls). Not through the outbox: a call is live, so an
 * answer lost on the way is a call that rings out, never one to place again later.
 *
 * Hanging up and transferring are for the member who placed it, or one who manages the account. A transfer is a transfer, so it needs the operator's yes to those too.
 *
 * Not here: the number's own checks (App\Kit\Calls\DialCheck), what a member writes on a call (App\Kit\Calls\CallWork), calls on now (App\Kit\Calls\LiveCalls), the page (SoftphoneController, console phone/).
 */
final class BrowserCalls
{
    /** The operator's yes to customers placing calls from the browser: none until it is given. */
    public const ALLOWED = 'softphone.allowed';

    /** The fields a client joins with (TelenowSession in @telenow/client). */
    private const JOIN = ['sessionId', 'transport', 'websocketUrl', 'livekitUrl', 'token', 'room'];

    /** A cost to ask a plan about: only whether one of its rules prices such a call matters, never the amount. */
    private const SAMPLE_USD = '0.01';

    private const SAMPLE_SECONDS = 60;

    public function __construct(
        private readonly Settings $settings,
        private readonly TelenowClient $telenow,
        private readonly ServicePause $pause,
        private readonly Pricing $pricing,
        private readonly PricingEngine $engine,
        private readonly AgentSettings $agentSettings,
        private readonly ConsentRule $consent,
        private readonly DialCheck $dial,
    ) {}

    public function allowed(): bool
    {
        return $this->settings->get(self::ALLOWED, false) === true;
    }

    public function allow(bool $allowed): void
    {
        $this->settings->put(self::ALLOWED, $allowed);
    }

    /** @return array{allowed: bool, transferAllowed: bool, paused: ?string, priced: bool, consentRequired: bool, callerIds: list<array{id: string, number: string}>} what the page offers */
    public function page(Workspace $workspace): array
    {
        return [
            'allowed' => $this->allowed(),
            'transferAllowed' => $this->transfersAllowed(),
            'paused' => $this->pause->for($workspace)['message'] ?? null,
            'priced' => $this->priced($workspace),
            'consentRequired' => $this->consent->wanted($workspace) === 'required',
            'callerIds' => $this->callerIds($workspace)->map(fn (Number $n) => ['id' => $n->id, 'number' => '+'.Call::digits($n->e164)])->values()->all(),
        ];
    }

    /** @return array{callId: string, session: array<string, string>} the kit's call, and what the browser joins it with */
    public function place(WorkspaceMember $member, array $input): array
    {
        $workspace = $member->workspace;
        if (! $this->allowed()) {
            throw new SoftphoneRefused(403, __('softphone.not_allowed'));
        }
        $paused = $this->pause->for($workspace);
        if ($paused !== null) {
            throw new SoftphoneRefused(409, $paused['message']);
        }
        $values = Validator::make($input, [
            'callerId' => ['required', 'string'],
            'number' => ['required', 'string', 'max:'.config('kit.softphone.number_max_length')],
        ], [], ['callerId' => __('softphone.fields.callerId'), 'number' => __('softphone.fields.number')])->validate();
        $from = Str::isUuid($values['callerId']) ? $this->callerIds($workspace)->firstWhere('id', $values['callerId']) : null;
        if ($from === null) {
            throw ValidationException::withMessages(['callerId' => [__('softphone.pick_caller_id')]]);
        }
        $to = $this->dial->callable($workspace, 'number', $values['number']);
        if (! $this->priced($workspace)) {
            throw new SoftphoneRefused(409, __('softphone.not_priced'));
        }
        try {
            $session = $this->telenow->initWebCall($workspace->telenow_key, ['mode' => config('kit.softphone.mode'), 'fromNumber' => $from->e164, 'toNumber' => $to]);
        } catch (TelenowError $e) {
            throw match ($e->status) {
                429 => new SoftphoneRefused(429, __('softphone.caller_id_busy')),
                403 => ValidationException::withMessages(['number' => [__('softphone.refused')]]),
                404 => ValidationException::withMessages(['callerId' => [__('softphone.caller_id_gone')]]),
                default => new SoftphoneRefused(503, __('softphone.unavailable')),
            };
        }
        $id = $session['sessionId'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new SoftphoneRefused(503, __('softphone.unavailable'));
        }
        $call = Call::query()->where('telenow_id', $id)->first() ?? (new Call)->forceFill(['workspace_id' => $workspace->id, 'telenow_id' => $id]);
        $call->forceFill(['placed_by' => $member->user_id] + ($call->exists ? [] : [
            'status' => $session['status'] ?? null,
            'direction' => config('kit.softphone.rated_as.direction'),
            'channel' => config('kit.softphone.rated_as.channel'),
            'from_number' => $from->e164,
            'to_number' => $to,
            'started_at' => now(),
            'detail_state' => 'pending',
            'reads' => 0,
            'next_read_at' => now()->addSeconds(config('kit.calls.reread_base_seconds')),
        ]))->save();

        return [
            'callId' => $call->id,
            'session' => array_filter(array_intersect_key($session, array_flip(self::JOIN)), fn ($v) => is_string($v) && $v !== ''),
        ];
    }

    /** End a call placed from the browser. One the voice platform no longer holds is over already. */
    public function hangUp(WorkspaceMember $member, Call $call): void
    {
        $this->mayHandle($member, $call);
        try {
            $this->telenow->endSession($call->workspace->telenow_key, $call->telenow_id);
        } catch (TelenowError $e) {
            if ($e->status !== 404) {
                throw new SoftphoneRefused(503, __('softphone.hang_up_failed'));
            }
        }
        $this->readSoon($call);
    }

    /** Hand a live call placed from the browser to another number, checked as a call to it would be. */
    public function transfer(WorkspaceMember $member, Call $call, array $input): void
    {
        if (! $this->transfersAllowed()) {
            throw new SoftphoneRefused(403, __('softphone.transfer_not_allowed'));
        }
        $this->mayHandle($member, $call);
        $paused = $this->pause->for($call->workspace);
        if ($paused !== null) {
            throw new SoftphoneRefused(409, $paused['message']);
        }
        $values = Validator::make($input, ['to' => ['required', 'string', 'max:'.config('kit.softphone.number_max_length')]], [], ['to' => __('softphone.fields.to')])->validate();
        $to = $this->dial->callable($call->workspace, 'to', $values['to']);
        try {
            $this->telenow->transferCall($call->workspace->telenow_key, $call->telenow_id, $to);
        } catch (TelenowError $e) {
            throw match ($e->status) {
                400, 404 => new SoftphoneRefused(409, __('softphone.call_over')),
                403 => ValidationException::withMessages(['to' => [__('softphone.refused')]]),
                502 => new SoftphoneRefused(503, __('softphone.transfer_failed')),
                default => new SoftphoneRefused(503, __('softphone.unavailable')),
            };
        }
        $this->readSoon($call);
    }

    /** @return list<array{id: string, name: string}> the price lists that have no price for a call from the browser (none while customers are not charged): on Admin → Catalog beside the switch */
    public function unpricedPlans(): array
    {
        if (! $this->pricing->inForce()) {
            return [];
        }
        $lists = $this->pricing->lists();
        $unpriced = [];
        foreach (PriceList::query()->orderBy('id')->get(['id', 'name']) as $plan) {
            try {
                $this->engine->price($lists, $plan->id, $this->pricing->settings()['usdRate'], $this->sample());
            } catch (PricingRefused) {
                $unpriced[] = ['id' => $plan->id, 'name' => $plan->name];
            }
        }

        return $unpriced;
    }

    private function transfersAllowed(): bool
    {
        return $this->allowed() && $this->agentSettings->transferAllowed();
    }

    /** The account's numbers a call can come from: its own, not being released, known to the voice platform. */
    private function callerIds(Workspace $workspace): \Illuminate\Support\Collection
    {
        return Number::query()->where('workspace_id', $workspace->id)->whereNull('released_at')->whereNotNull('telenow_id')->orderBy('e164')->get();
    }

    /** Whether the customer's plan prices such a call. While customers are not charged, nothing is priced and every call may go. */
    private function priced(Workspace $workspace): bool
    {
        if (! $this->pricing->inForce()) {
            return true;
        }
        try {
            $this->pricing->price($workspace, $this->sample());

            return true;
        } catch (PricingRefused) {
            return false;
        }
    }

    /** A call from the browser as a plan is asked about it: rated as Telenow rates one, with each part it charges. */
    private function sample(): array
    {
        $parts = array_fill_keys(config('kit.softphone.rated_parts'), self::SAMPLE_USD);
        $total = BigDecimal::of(self::SAMPLE_USD)->multipliedBy(count($parts));

        return ['kind' => 'call', 'attributes' => config('kit.softphone.rated_as'), 'durationSec' => self::SAMPLE_SECONDS, 'components' => $parts, 'totalUsd' => (string) $total];
    }

    private function mayHandle(WorkspaceMember $member, Call $call): void
    {
        if ($call->channel !== config('kit.softphone.rated_as.channel') || $call->placed_by === null) {
            throw new SoftphoneRefused(409, __('softphone.not_a_browser_call'));
        }
        if ((int) $call->placed_by !== (int) $member->user_id && ! in_array($member->role, config('kit.team.managers'), true)) {
            throw new SoftphoneRefused(403, __('softphone.not_yours'));
        }
        if (in_array($call->status, config('kit.calls.ended_statuses'), true)) {
            throw new SoftphoneRefused(409, __('softphone.call_over'));
        }
    }

    /** Read it again soon: it has ended, or changed. */
    private function readSoon(Call $call): void
    {
        if ($call->detail_state === 'pending') {
            $call->forceFill(['next_read_at' => now()])->save();
        }
    }
}
