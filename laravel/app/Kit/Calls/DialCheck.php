<?php

namespace App\Kit\Calls;

use App\Kit\Consent\ConsentRule;
use App\Kit\Sms\PhoneNumber;
use App\Models\ConsentRecord;
use App\Models\DncEntry;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * Whether a number may be dialled for an account, before the voice platform is asked: written with its + and country code, not on the account's do-not-call list
 * as the kit holds it, and with consent on file for that exact number while the account's calls need it. Telenow checks the last two again by its own lists.
 *
 * Used by a call placed from the browser (App\Kit\Softphone\BrowserCalls) and an agent calling one person (App\Kit\Agents\AgentCalls). Moved here from BrowserCalls unchanged.
 */
final class DialCheck
{
    public function __construct(private readonly ConsentRule $consent) {}

    /** A typed number as the voice platform is sent it, or a field error: with its + and country code, not on the do-not-call list, with consent on file if calls need it. */
    public function callable(Workspace $workspace, string $field, string $typed): string
    {
        $number = PhoneNumber::normalize($typed);
        if ($number === null) {
            throw ValidationException::withMessages([$field => [__('softphone.number_needs_plus')]]);
        }
        $listed = DncEntry::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')
            ->whereIn('phone_number', [$number, substr($number, 1)])->exists();
        if ($listed) {
            throw ValidationException::withMessages([$field => [__('softphone.on_dnc')]]);
        }
        if ($this->consent->wanted($workspace) === 'required' && ! $this->consented($workspace, $number)) {
            throw ValidationException::withMessages([$field => [__('softphone.needs_consent')]]);
        }

        return $number;
    }

    /** Consent on file for exactly this number, not withdrawn, begun and not ended: the voice platform matches a call to one by the number exactly. */
    private function consented(Workspace $workspace, string $number): bool
    {
        return ConsentRecord::query()->where('workspace_id', $workspace->id)->where('phone_number', $number)->whereNull('revoked_at')
            ->where('captured_at', '<=', now())->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }
}
