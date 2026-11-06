<?php

namespace App\Kit\Consent\Telenow;

use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\NotYet;
use App\Kit\Outbox\Outbox;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\ConsentRecord;
use App\Models\Workspace;

/**
 * Sends an account's records not yet sent, one request each (Telenow has no bulk route), config('kit.consent.push_per_run') a go, then comes back for the rest. Telenow keeps the same one twice and has no idempotency key, so the kit's id travels in the
 * evidence, and a try after a lost answer looks for it first. Its server block says only that the kit's server sent it, so who recorded it, from where, goes beside it.
 *
 * One Telenow refuses (400, 422) says so on its own row and the others carry on. Anything else stops here and is tried again. A record withdrawn while its request was out is withdrawn at Telenow too.
 */
final class PushConsentAtTelenow extends ConsentWrite
{
    public function __construct(TelenowClient $telenow, private readonly Manifest $manifest, private readonly Outbox $outbox)
    {
        parent::__construct($telenow);
    }

    public function send(OutboxEntry $entry): void
    {
        $workspace = Workspace::query()->findOrFail($entry->kit_id);
        [$key, $org] = $this->account($workspace);
        $pending = fn () => ConsentRecord::query()->where('workspace_id', $workspace->id)->where('sync_state', 'pending');
        foreach ($pending()->orderBy('created_at')->orderBy('id')->limit(config('kit.consent.push_per_run'))->get() as $row) {
            $this->push($key, $org, $row);
        }
        if ($pending()->exists()) {
            throw new NotYet(__('consent.account_not_ready'));
        }
    }

    private function push(string $key, string $org, ConsentRecord $row): void
    {
        if ($row->tried_at !== null) {
            foreach ($this->telenow->consentHistory($key, $org, $row->phone_number) as $held) {
                if (($held['evidence']['kitId'] ?? null) === $row->id) {
                    $this->saved($row, $held);

                    return;
                }
            }
        }
        $row->forceFill(['tried_at' => now()])->save();
        try {
            $answer = $this->telenow->recordConsent($key, $org, $this->body($row));
        } catch (TelenowError $e) {
            if (! in_array($e->status, [400, 422], true)) {
                throw $e;
            }
            $row->forceFill(['sync_state' => 'failed', 'sync_error' => __('consent.refused')])->save();

            return;
        }
        $this->saved($row, $this->manifest->object(self::RESOURCE, 'create', $answer));
    }

    private function saved(ConsentRecord $row, array $object): void
    {
        $row->refresh();
        $row->forceFill(['telenow_id' => $object['id'] ?? null, 'sync_state' => 'synced', 'sync_error' => null])->save();
        if ($row->revoked_at !== null) {
            $this->outbox->enqueue(self::RESOURCE, $row->id, 'revoke', ['phone' => $row->phone_number, 'note' => $row->revoke_note, 'workspaceId' => $row->workspace_id]);
        }
    }

    /** @return array<string, mixed> */
    private function body(ConsentRecord $row): array
    {
        $body = $this->manifest->writeBody(self::RESOURCE, 'create', [
            'phone_number' => $row->phone_number, 'consent_type' => $row->consent_type, 'scope' => $row->scope, 'source' => $row->source, 'disclosure_text' => $row->disclosure_text, 'disclosure_version' => $row->disclosure_version,
            'captured_at' => $row->captured_at->utc()->toIso8601ZuluString(), 'expires_at' => $row->expires_at?->utc()->toIso8601ZuluString(),
        ], $row->id);
        $body['evidence'] += array_filter(['note' => $row->note, 'recordedFrom' => array_filter(['user' => $row->recorded_by, 'ip' => $row->recorded_ip, 'userAgent' => $row->recorded_agent])]);

        return array_filter($body, fn ($v) => $v !== null);
    }
}
