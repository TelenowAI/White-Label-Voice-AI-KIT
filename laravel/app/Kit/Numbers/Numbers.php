<?php

namespace App\Kit\Numbers;

use App\Kit\Numbers\Telenow\NumberWrite;
use App\Kit\Outbox\Outbox;
use App\Kit\Manifest\Manifest;
use App\Kit\Pricing\Pricing;
use App\Kit\Pricing\PricingRefused;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\PhoneNumber;
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
 *   replaces a write Telenow gave up on (assign or release again is the way to try again);
 * - feed(): every number Telenow changed since the last read — one it released itself, say.
 *
 * The fees are charged from Telenow's ledger (App\Kit\Wallet\FeeCharges), not here.
 */
final class Numbers
{
    public function __construct(
        private readonly TelenowClient $telenow,
        private readonly Manifest $manifest,
        private readonly Outbox $outbox,
        private readonly Pricing $pricing,
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
        $agent = null;
        if ($agentId !== null) {
            $agent = Agent::query()->where('workspace_id', $number->workspace_id)->whereNull('deleted_at')->whereNotNull('telenow_id')->find($agentId);
            if ($agent === null) {
                throw ValidationException::withMessages(['agentId' => [__('numbers.unknown_agent')]]);
            }
        }
        DB::transaction(function () use ($number, $agent) {
            $this->replaceGivenUp($number);
            $number->forceFill(['agent_id' => $agent?->id, 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(NumberWrite::RESOURCE, $number->id, 'assign', ['agentId' => $agent?->telenow_id]);
        });
        $this->outbox->runFor(NumberWrite::RESOURCE, $number->id);

        return $number->fresh();
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
            if ($number->exists && $number->sync_state === 'pending') {
                continue; // the kit is changing it: its own write's answer comes first
            }
            $agent = $row['agent_id'] ?? null;
            $number->forceFill($this->manifest->columns(NumberWrite::RESOURCE, $row) + [
                'agent_id' => is_string($agent) ? Agent::query()->where('workspace_id', $workspace->id)->where('telenow_id', $agent)->value('id') : null,
                'sync_state' => 'synced', 'sync_error' => null,
            ])->save();
            if (is_string($row['updated_at'] ?? null) && $row['updated_at'] > $after) {
                $after = $row['updated_at'];
            }
        }
        $workspace->forceFill(['numbers_after' => $after])->save();

        return count($rows);
    }

    /** A write Telenow gave up on would hold every later one back: the new write says the whole outcome instead. */
    private function replaceGivenUp(PhoneNumber $number): void
    {
        if ($this->outbox->hasFailed(NumberWrite::RESOURCE, $number->id)) {
            $this->outbox->cancel(NumberWrite::RESOURCE, $number->id, __('numbers.replaced'));
        }
    }

    /** What a number fee would cost the customer on its plan: {amount, currency}, or null (not priced, or not in force). */
    private function price(Workspace $workspace, string $refKind, mixed $costUsd): ?array
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
