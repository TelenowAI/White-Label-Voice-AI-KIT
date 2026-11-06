<?php

namespace App\Kit\Consent\Telenow;

use App\Kit\Dnc\Dnc;
use App\Kit\Dnc\DncRefused;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\ConsentRecord;
use App\Models\Workspace;

/**
 * Withdraws a number's consent at Telenow, which also lists it as do-not-call in the person's own name and drops its waiting calls (recorded). Asking again is harmless, so a try after a lost answer is too. That number is read straight after, so it is on the
 * kit's copy now and not at a later feed.
 */
final class RevokeConsentAtTelenow extends ConsentWrite
{
    public function __construct(TelenowClient $telenow, private readonly Dnc $dnc)
    {
        parent::__construct($telenow);
    }

    public function send(OutboxEntry $entry): void
    {
        $workspace = Workspace::query()->findOrFail($entry->body['workspaceId']);
        [$key, $org] = $this->account($workspace);
        $phone = (string) $entry->body['phone'];
        $this->telenow->revokeConsent($key, $org, $phone, array_filter(['source' => config('kit.consent.revoke_source'), 'note' => $entry->body['note'] ?? null]));
        ConsentRecord::query()->where('workspace_id', $workspace->id)->where('phone_number', $phone)->whereNotNull('revoked_at')->whereNull('revoke_sent_at')->update(['revoke_sent_at' => now(), 'updated_at' => now()]);
        try {
            $this->dnc->readNumber($workspace, $phone);
        } catch (TelenowError|DncRefused) {
            // It comes with the next feed run.
        }
    }
}
