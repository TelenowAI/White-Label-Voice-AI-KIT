<?php

namespace App\Kit\Numbers;

use App\Kit\Manifest\Manifest;
use App\Kit\Numbers\Telenow\NumberWrite;
use App\Kit\Pricing\Pricing;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\PhoneNumber;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Numbers the voice platform gave up for a customer — when their rent was not paid, which is the operator's account at Telenow: a customer's
 * numbers renew on it — that can still be got back before the carrier lets them go (console → Numbers; contract/kit-api.yaml listLostNumbers,
 * getLostNumberBack; Telenow's listReclaimableNumbers and reclaimNumber, recorded in contract_k27numbers.py). Never one the customer released.
 *
 * - list(): each with when it can be got back until, whether it was asked for, its agent and whether it was that agent's caller id then, and what
 *   getting it back costs on the customer's plan (setup and a month's rent, from its last prices in USD); read from Telenow each time.
 * - getBack(): the customer's balance must cover that first, while charging is on (as buying does); then Telenow buys it again at once. Back: a NEW
 *   number, set up as the old one was (its agent, caller id, campaigns), which takes the old one's place in the kit too (Numbers::carryOver); the
 *   fees reach the wallet from Telenow's ledger, as a purchase's do (App\Kit\Wallet\FeeCharges; recorded: its rent on the new number). Or asked
 *   for: the carrier would not sell it back at once, so Telenow's staff finish it by hand before the deadline; when it comes back the numbers feed
 *   brings it, and it takes the old one's place then (Numbers::feed). Telenow's own words (its carrier's, its account's) never reach the customer.
 */
final class LostNumbers
{
    public function __construct(
        private readonly TelenowClient $telenow,
        private readonly Manifest $manifest,
        private readonly Numbers $numbers,
        private readonly Pricing $pricing,
    ) {}

    /** @return list<array{number: PhoneNumber, until: ?string, askedAt: ?string, unpaid: bool, setup: ?array, monthly: ?array, agent: ?Agent, wasCallerId: bool}> */
    public function list(Workspace $workspace): array
    {
        $out = [];
        foreach ($this->lost($workspace) as $row) {
            $binding = is_array($row['binding'] ?? null) ? $row['binding'] : [];
            $agent = is_string($binding['agentId'] ?? null)
                ? Agent::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')->where('telenow_id', $binding['agentId'])->first() : null;
            $out[] = [
                'number' => $this->kept($workspace, $row),
                'until' => is_string($row['reclaimDeadlineAt'] ?? null) ? $row['reclaimDeadlineAt'] : null,
                'askedAt' => is_string($row['reclaimRequestedAt'] ?? null) ? $row['reclaimRequestedAt'] : null,
                'unpaid' => in_array($row['releaseReason'] ?? null, config('kit.numbers.lost_unpaid_reasons'), true),
                'setup' => $this->numbers->price($workspace, 'number_setup', $row['lastSetupPriceUsd'] ?? null),
                'monthly' => $this->numbers->price($workspace, 'rental', $row['lastMonthlyRentUsd'] ?? null),
                'agent' => $agent,
                'wasCallerId' => ($binding['isDefaultOutbound'] ?? false) === true,
            ];
        }

        return $out;
    }

    /** @return array{outcome: string, number?: PhoneNumber, until?: ?string} restored (the number it came back as) or requested (until when) */
    public function getBack(Workspace $workspace, PhoneNumber $old): array
    {
        if ($old->got_back_as !== null) {
            throw new NumbersRefused(409, __('numbers.already_back'));
        }
        $row = collect($this->lost($workspace))->first(fn (array $r) => ($r['id'] ?? null) === $old->telenow_id && $old->telenow_id !== null);
        if ($row === null) {
            throw new NumbersRefused(409, __('numbers.not_lost'));
        }
        // Asked for already: Telenow says so again, without buying (recorded); nothing to charge for yet.
        if (! is_string($row['reclaimRequestedAt'] ?? null) && $this->pricing->inForce()) {
            $setup = $this->numbers->price($workspace, 'number_setup', $row['lastSetupPriceUsd'] ?? null);
            $monthly = $this->numbers->price($workspace, 'rental', $row['lastMonthlyRentUsd'] ?? null);
            if ($setup === null || $monthly === null) {
                throw new NumbersRefused(409, __('numbers.no_price'));
            }
            $due = BigDecimal::of($setup['amount'])->plus($monthly['amount']);
            if (BigDecimal::of($workspace->balance)->isLessThan($due)) {
                throw new NumbersRefused(409, __('numbers.not_enough_balance_back', ['amount' => (string) $due, 'currency' => $monthly['currency']]));
            }
        }
        try {
            $answer = $this->telenow->reclaimNumber($this->key($workspace), $old->telenow_id);
        } catch (TelenowError $e) {
            throw match ($e->status) {
                403 => new NumbersRefused(409, __('numbers.get_back_not_now')), // the operator's account cannot pay for it now
                400 => new NumbersRefused(409, __('numbers.get_back_too_late')),
                409 => new NumbersRefused(409, __('numbers.already_back')),
                404 => new NumbersRefused(409, __('numbers.not_lost')),
                default => new NumbersRefused(503, __('numbers.lost_unavailable')),
            };
        }
        if (($answer['status'] ?? null) === 'restored' && is_string($answer['number']['id'] ?? null)) {
            $new = $this->adopt($workspace, $answer['number'], $row);
            $this->numbers->carryOver($old, $new);
            $workspace->forceFill(['numbers_synced_at' => null])->save(); // its fees: on the next feed run

            return ['outcome' => 'restored', 'number' => $new->fresh()];
        }
        $asked = is_string($answer['requestedAt'] ?? null) ? Carbon::parse($answer['requestedAt']) : now();
        $old->forceFill(['get_back_asked_at' => $old->get_back_asked_at ?? $asked])->save();

        return ['outcome' => 'requested', 'until' => is_string($answer['deadline'] ?? null) ? $answer['deadline'] : ($row['reclaimDeadlineAt'] ?? null)];
    }

    /** Telenow's given-up numbers for the account, read now. */
    private function lost(Workspace $workspace): array
    {
        try {
            return array_values(array_filter($this->telenow->reclaimableNumbers($this->key($workspace)), 'is_array'));
        } catch (TelenowError) {
            throw new NumbersRefused(503, __('numbers.lost_unavailable'));
        }
    }

    /** The kit's row for a given-up number: as the numbers feed keeps it, or made now when the feed has not brought it yet. */
    private function kept(Workspace $workspace, array $row): PhoneNumber
    {
        $number = PhoneNumber::query()->where('workspace_id', $workspace->id)->where('telenow_id', $row['id'])->first()
            ?? (new PhoneNumber)->forceFill(['workspace_id' => $workspace->id, 'telenow_id' => $row['id'], 'e164' => (string) ($row['e164'] ?? ''), 'sync_state' => 'synced']);
        if ($number->released_at === null) {
            // Telenow has given it up: as the feed will say, with its agent and caller id gone (recorded).
            $number->forceFill(['released_at' => is_string($row['releasedAt'] ?? null) ? Carbon::parse($row['releasedAt']) : now(), 'agent_id' => null, 'agent_caller_id' => false]);
        }
        if ($number->isDirty()) {
            $number->save();
        }

        return $number;
    }

    /** The number it came back as, read again (the reclaim answer's row is the one before it was set up as the old one was), kept by the kit. */
    private function adopt(Workspace $workspace, array $restored, array $row): PhoneNumber
    {
        $object = $restored;
        try {
            $object = $this->manifest->object(NumberWrite::RESOURCE, 'read', $this->telenow->getNumber($this->key($workspace), $restored['id']));
        } catch (TelenowError) {
            // Kept as the reclaim answer says; the numbers feed brings the rest.
        }
        $monthly = $this->numbers->price($workspace, 'rental', $object['monthly_rent_usd'] ?? ($row['lastMonthlyRentUsd'] ?? null));
        $agent = $object['agent_id'] ?? null;
        $fill = fn (PhoneNumber $n) => $n->forceFill($this->manifest->columns(NumberWrite::RESOURCE, $object) + [
            'workspace_id' => $workspace->id,
            'telenow_id' => $restored['id'],
            'agent_id' => is_string($agent) ? Agent::query()->where('workspace_id', $workspace->id)->where('telenow_id', $agent)->value('id') : null,
            'monthly_price' => $monthly['amount'] ?? $n->monthly_price,
            'price_currency' => $monthly['currency'] ?? $n->price_currency,
            'sync_state' => 'synced',
            'sync_error' => null,
        ]);
        $number = PhoneNumber::query()->where('telenow_id', $restored['id'])->first() ?? new PhoneNumber;
        try {
            $fill($number)->save();
        } catch (UniqueConstraintViolationException) {
            $number = $fill(PhoneNumber::query()->where('telenow_id', $restored['id'])->firstOrFail()); // the feed brought it meanwhile
            $number->save();
        }

        return $number;
    }

    private function key(Workspace $workspace): string
    {
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '' || ! is_string($workspace->telenow_sub_account_id)) {
            throw new NumbersRefused(409, __('numbers.account_not_ready'));
        }

        return $workspace->telenow_key;
    }
}
