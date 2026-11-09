<?php

namespace App\Kit\Numbers;

use App\Kit\Numbers\Telenow\NumberWrite;
use App\Kit\Outbox\Outbox;
use App\Kit\Manifest\Manifest;
use App\Kit\Pricing\Pricing;
use App\Kit\Pricing\PricingRefused;
use App\Kit\Routing\RoutingConnection;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\Campaign;
use App\Models\PhoneNumber;
use App\Models\RoutingRule;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A customer's phone numbers (console → Numbers; contract/resources.yaml `numbers`; SELF_HOSTED_KIT_PLAN.md §4.6 c, §5 C5):
 *
 * - search(): numbers for sale, each with what it would cost the customer (setup and monthly rent, on its plan);
 * - buy(): the number found again (its provider and prices are Telenow's, never the browser's), refused when the balance
 *   does not cover the setup and first month (while the wallet is in force), then bought through the outbox at once;
 * - assign() / release(): which agent answers it; giving it back (its rent stops). Each says the whole outcome, so it
 *   replaces a write Telenow gave up on (assign or release again is the way to try again). A routed number keeps an agent:
 *   it takes the calls no rule does, and every call when this kit cannot answer in time (Telenow refuses it too, recorded);
 * - route(): whether calls to it ask the account's routing rules (App\Kit\Routing) who takes them: the account's endpoint
 *   at Telenow made sure of first (RoutingConnection::ensure), then the number marked through the outbox;
 * - callerId(): whether it is its agent's caller id when a call names none (the agent's other one stops being, as at Telenow);
 * - unanswered(): what a call to it does while no agent answers it: a short message, a busy line, or "unavailable". Giving the
 *   number an agent clears both, as Telenow does (recorded: even the same agent again);
 * - feed(): every number Telenow changed since the last read — one it released itself, say; a number its staff brought back
 *   after the customer asked (App\Kit\Numbers\LostNumbers) takes the old one's place (carryOver()).
 *
 * The fees are charged from Telenow's ledger (App\Kit\Wallet\FeeCharges), not here. Not here either: numbers given up for unpaid rent
 * (LostNumbers), renewal warnings (NumberRenewals).
 */
final class Numbers
{
    public function __construct(
        private readonly TelenowClient $telenow,
        private readonly Manifest $manifest,
        private readonly Outbox $outbox,
        private readonly Pricing $pricing,
        private readonly RoutingConnection $routing,
    ) {}

    /** @return list<array{listing: array, provider: string, setup: ?array, monthly: ?array}> */
    public function search(Workspace $workspace, array $input): array
    {
        [$key] = $this->ready($workspace);
        $values = Validator::make($input, [
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'type' => ['nullable', 'string', 'max:20'],
            'pattern' => ['nullable', 'string', 'regex:/^[0-9]{1,15}$/'],
        ], [], ['country' => __('numbers.fields.country'), 'type' => __('numbers.fields.type'), 'pattern' => __('numbers.fields.pattern')])->validate();
        $query = array_filter(['country' => strtoupper($values['country']), 'type' => $values['type'] ?? null,
            'pattern' => $values['pattern'] ?? null, 'limit' => config('kit.numbers.search_limit')]);
        try {
            $found = $this->telenow->searchNumbers($key, $query);
        } catch (TelenowError $e) {
            throw match ($e->status) {
                400, 422 => ValidationException::withMessages(['country' => [__('numbers.search_refused')]]),
                403 => new NumbersRefused(409, __('numbers.cannot_buy_now')), // the carrier is not open to this account
                default => new NumbersRefused(503, __('numbers.search_unavailable')),
            };
        }

        return array_map(fn (array $n) => [
            'listing' => $n,
            'provider' => (string) ($found['provider'] ?? ''),
            'setup' => $this->price($workspace, 'number_setup', $n['setup_price_usd'] ?? null),
            'monthly' => $this->price($workspace, 'rental', $n['monthly_rent_usd'] ?? null),
        ], $found['numbers'] ?? []);
    }

    public function buy(Workspace $workspace, array $input): PhoneNumber
    {
        $values = Validator::make($input, [
            'number' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
        ], [], ['number' => __('numbers.fields.number'), 'country' => __('numbers.fields.country')])->validate();
        $digits = preg_replace('/\D/', '', $values['number']);
        $offer = collect($this->search($workspace, ['country' => $values['country'], 'pattern' => $digits]))
            ->first(fn (array $o) => preg_replace('/\D/', '', (string) ($o['listing']['e164'] ?? '')) === $digits);
        if ($offer === null) {
            throw new NumbersRefused(409, __('numbers.not_available'));
        }
        if ($this->pricing->inForce()) {
            if ($offer['setup'] === null || $offer['monthly'] === null) {
                throw new NumbersRefused(409, __('numbers.no_price'));
            }
            $due = BigDecimal::of($offer['setup']['amount'])->plus($offer['monthly']['amount']);
            if (BigDecimal::of($workspace->balance)->isLessThan($due)) {
                throw new NumbersRefused(409, __('numbers.not_enough_balance', ['amount' => (string) $due, 'currency' => $offer['monthly']['currency']]));
            }
        }
        $listing = $offer['listing'];
        $number = DB::transaction(function () use ($workspace, $offer, $listing) {
            $number = (new PhoneNumber)->forceFill([
                'workspace_id' => $workspace->id,
                'e164' => $listing['e164'],
                'country' => $listing['country'] ?? null,
                'region' => $listing['region'] ?? null,
                'number_type' => $listing['number_type'] ?? null,
                'monthly_price' => $offer['monthly']['amount'] ?? null,
                'price_currency' => $offer['monthly']['currency'] ?? null,
                'rent_usd' => is_string($listing['monthly_rent_usd'] ?? null) ? $listing['monthly_rent_usd'] : null,
                'sync_state' => 'pending',
            ]);
            $number->save();
            $body = $this->manifest->writeBody(NumberWrite::RESOURCE, 'create', ['e164' => $listing['e164']]) + [
                'provider' => $offer['provider'],
                'extras' => ['monthlyRent' => $listing['monthly_rent'] ?? null, 'setupPrice' => $listing['setup_price'] ?? null],
            ];
            $this->outbox->enqueue(NumberWrite::RESOURCE, $number->id, 'create', $body, $number->id);

            return $number;
        });
        $this->outbox->runFor(NumberWrite::RESOURCE, $number->id);
        $workspace->forceFill(['numbers_synced_at' => null])->save(); // its fees: on the next feed run

        return $number->fresh();
    }

    public function assign(PhoneNumber $number, ?string $agentId): PhoneNumber
    {
        if ($number->telenow_id === null) {
            throw new NumbersRefused(409, __('numbers.not_bought_yet'));
        }
        if ($agentId === null && $number->routed) {
            throw new NumbersRefused(409, __('numbers.routed_needs_agent'));
        }
        $agent = null;
        if ($agentId !== null) {
            $agent = Agent::query()->where('workspace_id', $number->workspace_id)->whereNull('deleted_at')->whereNotNull('telenow_id')->find($agentId);
            if ($agent === null) {
                throw ValidationException::withMessages(['agentId' => [__('numbers.unknown_agent')]]);
            }
        }
        DB::transaction(function () use ($number, $agent) {
            $this->replaceGivenUp($number);
            // Telenow clears both whenever the agent is set, even to the same one (recorded): its answer says so too.
            $number->forceFill(['agent_id' => $agent?->id, 'agent_caller_id' => false, 'inbound_action' => null, 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(NumberWrite::RESOURCE, $number->id, 'assign', ['agentId' => $agent?->telenow_id]);
        });
        $this->outbox->runFor(NumberWrite::RESOURCE, $number->id);

        return $number->fresh();
    }

    public function route(PhoneNumber $number, bool $routed): PhoneNumber
    {
        if ($number->telenow_id === null || $number->released_at !== null) {
            throw new NumbersRefused(409, __('numbers.not_bought_yet'));
        }
        if ($routed) {
            if ($number->agent === null || $number->agent->deleted_at !== null) {
                throw new NumbersRefused(409, __('numbers.routing_needs_agent'));
            }
            $this->routing->ensure($number->workspace()->firstOrFail());
        }
        DB::transaction(function () use ($number, $routed) {
            $this->replaceGivenUp($number);
            $number->forceFill(['routed' => $routed, 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(NumberWrite::RESOURCE, $number->id, 'route', ['routed' => $routed]);
        });
        $this->outbox->runFor(NumberWrite::RESOURCE, $number->id);

        return $number->fresh();
    }

    /** Make the number its agent's caller id when a call names none, or stop it being. The agent's other one stops being at once, as at Telenow. */
    public function callerId(PhoneNumber $number, bool $on): PhoneNumber
    {
        $this->live($number);
        // Telenow refuses either way for a number with no agent (recorded): it is nobody's caller id.
        if ($number->agent_id === null || ($on && ($number->agent === null || $number->agent->deleted_at !== null))) {
            throw new NumbersRefused(409, __('numbers.caller_id_needs_agent'));
        }
        DB::transaction(function () use ($number, $on) {
            $this->replaceGivenUp($number);
            if ($on) {
                PhoneNumber::query()->where('workspace_id', $number->workspace_id)->where('agent_id', $number->agent_id)
                    ->whereKeyNot($number->id)->where('agent_caller_id', true)->update(['agent_caller_id' => false]);
            }
            $number->forceFill(['agent_caller_id' => $on, 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(NumberWrite::RESOURCE, $number->id, 'caller_id', ['on' => $on]);
        });
        $this->outbox->runFor(NumberWrite::RESOURCE, $number->id);

        return $number->fresh();
    }

    /** What a call to the number does while no agent answers it: one of config('kit.numbers.unanswered') (message, busy, unavailable). */
    public function unanswered(PhoneNumber $number, string $choice): PhoneNumber
    {
        $this->live($number);
        $action = config('kit.numbers.unanswered')[$choice] ?? null;
        if (! is_string($action)) {
            throw ValidationException::withMessages(['unanswered' => [__('numbers.unknown_unanswered')]]);
        }
        if ($number->agent_id !== null) {
            throw new NumbersRefused(409, __('numbers.unanswered_has_agent'));
        }
        DB::transaction(function () use ($number, $action) {
            $this->replaceGivenUp($number);
            $number->forceFill(['inbound_action' => $action, 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(NumberWrite::RESOURCE, $number->id, 'unanswered', ['action' => $action]);
        });
        $this->outbox->runFor(NumberWrite::RESOURCE, $number->id);

        return $number->fresh();
    }

    /** The kit's word for what a call to the number does while no agent answers it, from Telenow's (none: the account's default, a message). */
    public static function unansweredOf(PhoneNumber $number): string
    {
        return config('kit.numbers.unanswered_read_as')[$number->inbound_action ?? ''] ?? 'message';
    }

    /**
     * A number that came back takes the old one's place: the account's campaigns and routing rules that named the old one name the new one (Telenow
     * set the new one up as the old was: its agent, caller id and campaigns, recorded), and the old one says what it came back as.
     */
    public function carryOver(PhoneNumber $old, PhoneNumber $new): void
    {
        DB::transaction(function () use ($old, $new) {
            $old->forceFill(['got_back_as' => $new->id])->save();
            Campaign::query()->where('workspace_id', $old->workspace_id)->where('phone_number_id', $old->id)->update(['phone_number_id' => $new->id]);
            foreach (RoutingRule::query()->where('workspace_id', $old->workspace_id)->get() as $rule) {
                $ids = array_values((array) $rule->numbers);
                if (in_array($old->id, $ids, true)) {
                    $rule->forceFill(['numbers' => array_values(array_unique(array_map(fn ($id) => $id === $old->id ? $new->id : $id, $ids)))])->save();
                }
            }
        });
    }

    public function release(PhoneNumber $number): void
    {
        if ($number->telenow_id === null && $number->sync_state === 'failed') {
            $this->outbox->cancel(NumberWrite::RESOURCE, $number->id, __('numbers.cancelled'));
            $number->forceFill(['released_at' => now(), 'sync_state' => 'synced', 'sync_error' => null])->save();

            return;
        }
        DB::transaction(function () use ($number) {
            $this->replaceGivenUp($number);
            $number->forceFill(['released_at' => $number->released_at ?? now(), 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(NumberWrite::RESOURCE, $number->id, 'delete', null);
        });
        $this->outbox->runFor(NumberWrite::RESOURCE, $number->id);
    }

    /** Every number Telenow changed since the last read (released ones too). Answers how many it saw. */
    public function feed(Workspace $workspace): int
    {
        [$key] = $this->ready($workspace);
        $after = $workspace->numbers_after ?? $workspace->created_at->utc()->format('Y-m-d\TH:i:s.v\Z');
        $rows = $this->telenow->numbersChangedAfter($key, $after);
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }
            $number = PhoneNumber::query()->where('telenow_id', $id)->first()
                ?? (new PhoneNumber)->forceFill(['workspace_id' => $workspace->id, 'telenow_id' => $id]);
            $new = ! $number->exists;
            if ($number->exists && $number->sync_state === 'pending') {
                continue; // the kit is changing it: its own write's answer comes first
            }
            $agent = $row['agent_id'] ?? null;
            $number->forceFill($this->manifest->columns(NumberWrite::RESOURCE, $row) + [
                'agent_id' => is_string($agent) ? Agent::query()->where('workspace_id', $workspace->id)->where('telenow_id', $agent)->value('id') : null,
                'sync_state' => 'synced', 'sync_error' => null,
            ])->save();
            if ($new && $number->released_at === null) {
                $this->adoptAskedFor($number);
            }
            if (is_string($row['updated_at'] ?? null) && $row['updated_at'] > $after) {
                $after = $row['updated_at'];
            }
        }
        $workspace->forceFill(['numbers_after' => $after])->save();

        return count($rows);
    }

    /** A number Telenow's staff brought back after the account asked (LostNumbers): new to the kit, with the digits of one asked for and not yet back. */
    private function adoptAskedFor(PhoneNumber $new): void
    {
        $digits = preg_replace('/\D/', '', $new->e164);
        $old = PhoneNumber::query()->where('workspace_id', $new->workspace_id)->whereKeyNot($new->id)->whereNotNull('released_at')
            ->whereNotNull('get_back_asked_at')->whereNull('got_back_as')->orderByDesc('get_back_asked_at')->get()
            ->first(fn (PhoneNumber $n) => preg_replace('/\D/', '', $n->e164) === $digits);
        if ($old !== null) {
            $this->carryOver($old, $new);
        }
    }

    /** A number the account holds at Telenow: bought, and not given back. */
    private function live(PhoneNumber $number): void
    {
        if ($number->telenow_id === null || $number->released_at !== null) {
            throw new NumbersRefused(409, __('numbers.not_bought_yet'));
        }
    }

    /** A write Telenow gave up on would hold every later one back: the new write says the whole outcome instead. */
    private function replaceGivenUp(PhoneNumber $number): void
    {
        if ($this->outbox->hasFailed(NumberWrite::RESOURCE, $number->id)) {
            $this->outbox->cancel(NumberWrite::RESOURCE, $number->id, __('numbers.replaced'));
        }
    }

    /** What a number fee would cost the customer on its plan: {amount, currency}, or null (not priced, or not in force). */
    public function price(Workspace $workspace, string $refKind, mixed $costUsd): ?array
    {
        if (! $this->pricing->inForce() || ! is_string($costUsd) || ! preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/', $costUsd)) {
            return null;
        }
        try {
            $priced = $this->pricing->price($workspace, ['kind' => 'fee', 'attributes' => ['refKind' => $refKind], 'costUsd' => $costUsd]);
        } catch (PricingRefused) {
            return null;
        }

        return ['amount' => $priced['total'], 'currency' => $priced['currency']];
    }

    /** @return array{0: string} the account's key, or the refusal while it is being set up */
    private function ready(Workspace $workspace): array
    {
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '' || ! is_string($workspace->telenow_sub_account_id)) {
            throw new NumbersRefused(409, __('numbers.account_not_ready'));
        }

        return [$workspace->telenow_key];
    }
}
