<?php

namespace App\Kit\Consent\Telenow;

use App\Kit\Consent\ConsentRule;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;

/**
 * Tells Telenow the rule the kit wants when the write's turn comes. Telenow refuses `off` with 403 while the account billing this one requires consent (recorded): that is an answer and not a failure, so the rule stays on and the kit remembers who holds it.
 */
final class SetConsentRuleAtTelenow extends ConsentWrite
{
    public function __construct(TelenowClient $telenow, private readonly ConsentRule $rule)
    {
        parent::__construct($telenow);
    }

    public function send(OutboxEntry $entry): void
    {
        $workspace = Workspace::query()->findOrFail($entry->kit_id);
        [$key, $org] = $this->account($workspace);
        $wanted = $this->rule->wanted($workspace);
        try {
            $this->telenow->setConsentEnforcement($key, $org, $wanted);
        } catch (TelenowError $e) {
            if ($e->status !== 403 || $wanted !== 'off') {
                throw $e;
            }
            $workspace->forceFill(['consent_mode' => 'required', 'consent_locked' => true])->save();

            return;
        }
        $workspace->forceFill(['consent_mode' => $wanted])->save();
    }
}
