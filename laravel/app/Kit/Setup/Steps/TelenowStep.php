<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Settings\Settings;
use App\Kit\Setup\Check;
use App\Kit\Setup\Field;
use App\Kit\Setup\SetupStep;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step 4: the operator's Telenow key (SELF_HOSTED_KIT_PLAN.md §4.1.3, §5 C1). The key is checked with
 * Telenow before it is kept: `GET /api/v1/me` must name a top-level account (no `parent_org_id`) and a key role
 * that may manage sub-accounts (config('kit.telenow.operator_roles')). The step then reads the account's billing
 * (`GET /api/orgs/{org}/billing/summary` → `spendState`) and shows its mode and whether Telenow lets it spend:
 * the wizard may finish while it cannot, but says the platform stays paused until it can (§4.6 c).
 *
 * The key is stored encrypted and never shown again; leaving the field empty re-checks the saved one.
 * Not here: the webhook (K1), customers' keys (each sub-account's, K1).
 */
final class TelenowStep extends SetupStep
{
    public function __construct(private readonly Settings $settings, private readonly TelenowClient $telenow) {}

    public function id(): string
    {
        return 'telenow';
    }

    public function done(): bool
    {
        return $this->settings->has('telenow.api_key');
    }

    public function fields(Request $request, bool $mayRead): array
    {
        $saved = $mayRead && $this->done();

        return [new Field('apiKey', $this->text('fields.apiKey'), 'secret', ! $saved, null, $this->text('help.apiKey'), [], null, $saved)];
    }

    public function facts(Request $request): array
    {
        $account = $this->settings->get('telenow.account');
        if (! is_array($account)) {
            return [];
        }
        $facts = [
            ['label' => $this->text('facts.account'), 'value' => (string) ($account['org_name'] ?? $account['org_id'])],
            ['label' => $this->text('facts.key'), 'value' => trim(($account['key_name'] ?? '').' ('.$account['key_role'].')')],
        ];
        $billing = $this->settings->get('telenow.billing');
        if (is_array($billing)) {
            $facts[] = ['label' => $this->text('facts.mode'), 'value' => $this->text("modes.{$billing['mode']}")];
            $facts[] = ['label' => $this->text('facts.can_spend'), 'value' => $billing['canSpend']
                ? $this->text('can_spend_yes')
                : $this->text('can_spend_no', ['why' => $this->text('stopped_by.'.($billing['stoppedBy'] ?? 'unknown'))])];
        }

        return $facts;
    }

    public function checks(Request $request, bool $mayRead): array
    {
        if (! $mayRead || ! $this->done()) {
            return [];
        }
        $billing = $this->settings->get('telenow.billing');
        if (! is_array($billing)) {
            return [new Check('billing', $this->text('billing_unread'), Check::WARNING, $this->settings->get('telenow.billing_error'))];
        }
        if (! $billing['canSpend']) {
            return [new Check('billing', $this->text('paused'), Check::WARNING, $this->text('stopped_by.'.($billing['stoppedBy'] ?? 'unknown')))];
        }

        return [new Check('billing', $this->text('can_spend_yes'), Check::OK)];
    }

    public function submit(Request $request, array $input): void
    {
        $saved = $this->settings->get('telenow.api_key');
        $values = Validator::make($input, [
            'apiKey' => [$saved ? 'nullable' : 'required', 'string', 'max:200', 'regex:/^\S+$/'],
        ], [], ['apiKey' => $this->text('fields.apiKey')])->validate();
        $key = ($values['apiKey'] ?? '') !== '' ? $values['apiKey'] : $saved;

        try {
            $me = $this->telenow->whoAmI($key);
        } catch (TelenowError $e) {
            throw ValidationException::withMessages(['apiKey' => $e->status === 0
                ? $this->text('unreachable', ['error' => $e->getMessage()])
                : $this->text('refused', ['error' => $e->getMessage()])]);
        }
        if (($me['parent_org_id'] ?? null) !== null) {
            throw ValidationException::withMessages(['apiKey' => $this->text('sub_account', ['org' => $me['org_name'] ?? $me['org_id']])]);
        }
        $roles = config('kit.telenow.operator_roles');
        if (! in_array($me['key_role'] ?? null, $roles, true)) {
            throw ValidationException::withMessages(['apiKey' => $this->text('wrong_role', [
                'role' => (string) ($me['key_role'] ?? '?'), 'roles' => implode(', ', $roles),
            ])]);
        }

        $billing = null;
        $billingError = null;
        try {
            $state = $this->telenow->billingSummary($key, $me['org_id'])['spendState'] ?? null;
            if (is_array($state)) {
                $billing = array_intersect_key($state, array_flip(['mode', 'state', 'canSpend', 'stoppedBy']));
            } else {
                $billingError = $this->text('no_spend_state');
            }
        } catch (TelenowError $e) {
            $billingError = $e->getMessage();
        }

        $this->settings->put('telenow.api_key', $key, secret: true);
        $this->settings->put('telenow.account', array_intersect_key($me, array_flip(['org_id', 'org_name', 'key_id', 'key_name', 'key_role'])));
        $this->settings->put('telenow.billing', $billing);
        $this->settings->put('telenow.billing_error', $billingError);
    }
}
