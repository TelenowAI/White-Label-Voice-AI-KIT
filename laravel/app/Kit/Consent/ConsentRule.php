<?php

namespace App\Kit\Consent;

use App\Kit\Consent\Telenow\ConsentWrite;
use App\Kit\Outbox\Outbox;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a customer's calls need consent on file. Three can say yes: the customer, the operator for everyone (Admin → Catalog), and the Telenow account the service runs under, which a sub-account copies when it is made and cannot be
 * turned off under (recorded: 403, even for one that is off).
 *
 * Turning it on stops every call to a number with no consent, so the page shows how many waiting calls that is first. `kit:consent` tells each account of a change, at most config('kit.consent.rule_sweep_per_run') a run: a new
 * customer's account too, once it has a key. What Telenow says when the page reads it corrects the kit's copy, since it alone knows about its account's rule.
 *
 * Not here: the records (ConsentLedger), sending (Telenow\SetConsentRuleAtTelenow).
 */
final class ConsentRule
{
    public const FOR_EVERYONE = 'consent.required_for_all';

    public function __construct(private readonly Settings $settings, private readonly Outbox $outbox, private readonly TelenowClient $telenow) {}

    public function requiredForEveryone(): bool
    {
        return $this->settings->get(self::FOR_EVERYONE) === true;
    }

    public function saveForEveryone(bool $required): void
    {
        $this->settings->put(self::FOR_EVERYONE, $required);
    }

    /** The rule the kit wants at Telenow for this account. */
    public function wanted(Workspace $workspace): string
    {
        return $this->requiredForEveryone() || $workspace->consent_required || $workspace->consent_locked ? 'required' : 'off';
    }

    /** @return array{required: bool, byOperator: bool, byCustomer: bool, byProvider: bool, saving: bool, waiting: ?array{total: int, covered: int, blocked: int}} */
    public function state(Workspace $workspace): array
    {
        $waiting = null;
        if (is_string($workspace->telenow_key) && is_string($workspace->telenow_sub_account_id)) {
            try {
                $live = $this->telenow->consentCoverage($workspace->telenow_key, $workspace->telenow_sub_account_id);
                $waiting = ['total' => (int) ($live['pending_total'] ?? 0), 'covered' => (int) ($live['pending_covered'] ?? 0), 'blocked' => (int) ($live['pending_blocked'] ?? 0)];
                $this->correct($workspace, $live['mode'] ?? null);
            } catch (TelenowError) {
                // The rest of the page still reads.
            }
        }
        $wanted = $this->wanted($workspace);

        return [
            'required' => $wanted === 'required', 'byOperator' => $this->requiredForEveryone(), 'byCustomer' => (bool) $workspace->consent_required, 'byProvider' => (bool) $workspace->consent_locked,
            'saving' => $workspace->consent_mode !== $wanted, 'waiting' => $waiting,
        ];
    }

    /** A customer's own choice. Through the outbox, so it arrives; a refusal from the account above leaves it on, and says why. */
    public function setRequired(Workspace $workspace, bool $required): void
    {
        if (! $required && $this->requiredForEveryone()) {
            throw new ConsentRefused(409, __('consent.required_by_operator'));
        }
        $workspace->forceFill(['consent_required' => $required, 'consent_locked' => false])->save();
        $this->send($workspace);
        if (! $required && $workspace->fresh()->consent_locked) {
            throw new ConsentRefused(409, __('consent.held_by_provider'));
        }
    }

    /** How many accounts are not yet where the operator asked: on the page beside the switch. */
    public function stillToTell(): int
    {
        return $this->requiredForEveryone() ? $this->different()->count() : 0;
    }

    /** @return int how many accounts it wrote to */
    public function sweep(): int
    {
        $written = 0;
        // One with a write on its way already is left out, so a few stuck ones cannot hold every run's places.
        $due = $this->different()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('telenow_outbox')->where('resource', ConsentWrite::RULE_RESOURCE)->whereColumn('kit_id', 'workspaces.id')->whereNull('done_at'));
        foreach ($due->orderBy('id')->limit(config('kit.consent.rule_sweep_per_run'))->get() as $workspace) {
            $this->send($workspace);
            $written++;
        }

        return $written;
    }

    /** Accounts with a key whose rule at Telenow is not the one wanted. */
    private function different(): Builder
    {
        return Workspace::query()->whereNotNull('telenow_key')->whereNotNull('telenow_sub_account_id')
            ->when($this->requiredForEveryone(), fn (Builder $q) => $q->where('consent_mode', 'off'), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where(fn (Builder $on) => $on->where('consent_mode', 'off')->where(fn (Builder $y) => $y->where('consent_required', true)->orWhere('consent_locked', true)))
                ->orWhere(fn (Builder $off) => $off->where('consent_mode', 'required')->where('consent_required', false)->where('consent_locked', false))));
    }

    /** Telenow alone knows its account's rule: what it says is kept, and one the kit did not ask for is held by the account above. */
    private function correct(Workspace $workspace, mixed $mode): void
    {
        if (! is_string($mode) || $mode === $workspace->consent_mode || $this->outbox->hasUnfinished(ConsentWrite::RULE_RESOURCE, $workspace->id)) {
            return;
        }
        $workspace->forceFill(['consent_mode' => $mode, 'consent_locked' => $mode === 'required' && $this->wanted($workspace) === 'off' ? true : $workspace->consent_locked])->save();
    }

    private function send(Workspace $workspace): void
    {
        if ($this->outbox->hasFailed(ConsentWrite::RULE_RESOURCE, $workspace->id)) {
            $this->outbox->cancel(ConsentWrite::RULE_RESOURCE, $workspace->id, 'superseded');
        }
        if (! $this->outbox->hasUnfinished(ConsentWrite::RULE_RESOURCE, $workspace->id)) {
            $this->outbox->enqueue(ConsentWrite::RULE_RESOURCE, $workspace->id, 'update', null);
        }
        $this->outbox->runFor(ConsentWrite::RULE_RESOURCE, $workspace->id);
    }
}
