<?php

namespace App\Kit\Telenow;

use App\Kit\Support\Json;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The kit's calls to Telenow's API (contract/telenow/openapi.yaml, the released document). Server-side only:
 * the key is the operator's or a customer's, and never reaches a browser (SELF_HOSTED_KIT_PLAN.md §3.6).
 *
 * So far: the setup wizard's two reads; the operator's sub-account calls that make, recover, suspend and resume a
 * customer (§5 C2, C6) and the kit's one events endpoint (§5 C4); and, with a customer's own key, its agents, a
 * browser test call (§3.8 a, §5 C7), its calls (§3.8 c-d) and what each cost (§5 C5), its do-not-call list, its phone numbers and the fees they cost (its ledger); with the operator's, its
 * sub-accounts' spend and limits. Every call answers the decoded JSON body (a recording,
 * its audio stream) or throws TelenowError — with Telenow's own refusal
 * text when it refused, status 0 when it could not be reached. A body is decoded whatever its Content-Type says: a
 * create replayed under the same Idempotency-Key comes back as text/plain (Telenow 2446ecb).
 *
 * Not here: deciding what an answer means (the caller), retries and their order (App\Kit\Outbox).
 */
final class TelenowClient
{
    /** GET /api/v1/me (`whoAmI`): the key's org, its parent if it is a sub-account, and the key's role. */
    public function whoAmI(string $key): array
    {
        return $this->send('GET', $key, '/api/v1/me');
    }

    /**
     * POST /api/v1/sub-accounts (`createSubAccount`): the sub-account and its own key, which Telenow shows only here.
     * Replayed with the same Idempotency-Key within 24 h, Telenow answers the original again, key and all.
     */
    public function createSubAccount(string $operatorKey, array $body, string $idempotencyKey): array
    {
        return $this->send('POST', $operatorKey, '/api/v1/sub-accounts', $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /** GET /api/v1/sub-accounts?externalId= (`listSubAccounts`): closed ones included. */
    public function subAccountsByExternalId(string $operatorKey, string $externalId): array
    {
        return $this->send('GET', $operatorKey, '/api/v1/sub-accounts?'.http_build_query(['externalId' => $externalId]))['subAccounts'] ?? [];
    }

    /** POST /api/v1/sub-accounts/{id}/keys (`rotateSubAccountKey`): a new key; the others are revoked. */
    public function rotateSubAccountKey(string $operatorKey, string $subAccountId, array $body): array
    {
        return $this->send('POST', $operatorKey, '/api/v1/sub-accounts/'.rawurlencode($subAccountId).'/keys', $body);
    }

    /** POST /api/v1/sub-accounts/{id}/suspend (`suspendSubAccount`): ends its live calls; suspending again is 200. */
    /** DELETE /api/v1/sub-accounts/{id} (`closeSubAccount`): closed for good; 409 while it still holds numbers. */
    public function closeSubAccount(string $operatorKey, string $subAccountId): array
    {
        return $this->send('DELETE', $operatorKey, '/api/v1/sub-accounts/'.rawurlencode($subAccountId));
    }

    public function suspendSubAccount(string $operatorKey, string $subAccountId, ?string $reason): array
    {
        return $this->send('POST', $operatorKey, '/api/v1/sub-accounts/'.rawurlencode($subAccountId).'/suspend', $reason === null ? [] : ['reason' => $reason]);
    }

    /** POST /api/v1/sub-accounts/{id}/resume (`resumeSubAccount`). */
    public function resumeSubAccount(string $operatorKey, string $subAccountId): array
    {
        return $this->send('POST', $operatorKey, '/api/v1/sub-accounts/'.rawurlencode($subAccountId).'/resume');
    }

    /** GET /api/agent-defaults (`getAgentDefaults`): the provider stack Telenow recommends for a new agent; '' = none. */
    public function agentDefaults(string $key): array
    {
        return $this->send('GET', $key, '/api/agent-defaults')['data'] ?? [];
    }

    /**
     * GET /api/providers/tts/{provider}/voices (`listProviderVoices`): a voice provider's whole list, of one model when it
     * has a default one (`data`, `origin`, `offered`). Open at Telenow: the key is ignored, and sent like any other.
     */
    public function providerVoices(string $key, string $provider, ?string $model): array
    {
        return $this->send('GET', $key, '/api/providers/tts/'.rawurlencode($provider).'/voices'.($model === null ? '' : '?'.http_build_query(['model' => $model])));
    }

    /** GET /api/catalog (`getCatalog`): every provider Telenow offers, with models, voices and Telenow's prices (`data`). */
    public function catalog(string $key): array
    {
        $data = $this->send('GET', $key, '/api/catalog')['data'] ?? null;
        if (! is_array($data)) {
            throw new TelenowError(200, 'The catalog had no data.');
        }

        return $data;
    }

    /**
     * POST /api/providers/tts/{provider}/preview (`previewVoice`): a short WAV clip of a voice. Paid at Telenow, so it
     * meets the key's account's spend check; throttled per org.
     *
     * @return array{type: string, bytes: string}
     */
    public function previewVoice(string $key, string $provider, array $body): array
    {
        return $this->audio($key, '/api/providers/tts/'.rawurlencode($provider).'/preview', $body);
    }

    /**
     * GET /api/catalog/s2s (`getRealtimeCatalog`): whether the platform runs realtime (speech-to-speech) voices, and its providers, their models and each model's voices; an
     * empty list, never an error, while it does not (recorded).
     *
     * @return array{enabled?: mixed, providers?: mixed}
     */
    public function realtimeCatalog(string $key): array
    {
        $data = $this->send('GET', $key, '/api/catalog/s2s')['data'] ?? null;
        if (! is_array($data)) {
            throw new TelenowError(200, 'The realtime catalog had no data.');
        }

        return $data;
    }

    /**
     * POST /api/providers/s2s/{provider}/preview (`previewRealtimeVoice`): a short WAV clip of a realtime voice, from one short realtime session. 404 while the platform
     * runs no realtime voices; throttled per account (recorded).
     *
     * @return array{type: string, bytes: string}
     */
    public function previewRealtimeVoice(string $key, string $provider, array $body): array
    {
        return $this->audio($key, '/api/providers/s2s/'.rawurlencode($provider).'/preview', $body);
    }

    /**
     * POST /api/agents/{id}/voice-preview (`previewAgentVoice`): a short WAV clip in the agent's own voice.
     *
     * @return array{type: string, bytes: string}
     */
    public function previewAgentVoice(string $key, string $agentId, array $body): array
    {
        return $this->audio($key, '/api/agents/'.rawurlencode($agentId).'/voice-preview', $body);
    }

    /** POST /api/agents (`createAgent`): the whole answer (`data` is the agent as stored). */
    public function createAgent(string $key, array $body, string $idempotencyKey): array
    {
        return $this->send('POST', $key, '/api/agents', $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * PUT /api/agents/{id} (`updateAgent`): keeps every field it is not sent; answers `data` and `version`. With `$ifVersion`, it
     * saves only if the agent is still at that version: otherwise 409, with nothing written.
     */
    public function updateAgent(string $key, string $agentId, array $body, ?int $ifVersion = null): array
    {
        return $this->send('PUT', $key, '/api/agents/'.rawurlencode($agentId), $body, $ifVersion === null ? [] : ['If-Match' => "\"{$ifVersion}\""]);
    }

    /** GET /api/agents/{id} (`getAgent`), with a stored `{}` kept as `{}`, so its blobs can be written back as they are. */
    public function agentAsStored(string $key, string $agentId): array
    {
        return Json::decode($this->request('GET', $key, '/api/agents/'.rawurlencode($agentId))->body());
    }

    /** The version the agent is at: the one GET /api/agents/{id}/versions (`listAgentVersions`) lists first, 0 for an agent with none. */
    public function agentVersion(string $key, string $agentId): int
    {
        return (int) ($this->send('GET', $key, '/api/agents/'.rawurlencode($agentId).'/versions')['data']['versions'][0]['version'] ?? 0);
    }

    /**
     * POST /api/agents/prompt/validate (`validatePrompt`): a prompt (and a greeting) checked as Telenow checks one on a save and on a call — its
     * conditional sections' structure (`errors`: a save refuses them) and the lint (`warnings`) — and, given `values`, the prompt as a call with them
     * would get it (`rendered`, `decisions`). `data` of the answer. Calls no model.
     */
    public function validatePrompt(string $key, array $body): array
    {
        return $this->send('POST', $key, '/api/agents/prompt/validate', $body)['data'] ?? [];
    }

    /**
     * GET /api/agents/provider-health (`getProviderHealth`): the speech, voice and language model failures on the key's own account's calls in the
     * last `minutes` (1 to 1440), grouped by part, provider, key and cause, `data` of the answer. An operator's key reads its own account only
     * (recorded: a sub-account's failures are not in it).
     */
    public function providerHealth(string $key, int $minutes): array
    {
        return $this->send('GET', $key, '/api/agents/provider-health?'.http_build_query(['minutes' => $minutes]))['data'] ?? [];
    }

    /** POST /api/agents/flow/validate (`validateFlow`): a graph checked as a call would read it. Telenow stores any graph, and discards a bad one on every call. */
    public function validateFlow(string $key, array $flow, array $variables): array
    {
        return $this->send('POST', $key, '/api/agents/flow/validate', ['flow' => $flow] + ($variables === [] ? [] : ['variables' => $variables]))['data'] ?? [];
    }

    /** GET /api/agents/{id}/versions (`listAgentVersions`): its saved versions, newest first, at most 100. */
    public function agentVersions(string $key, string $agentId): array
    {
        return $this->send('GET', $key, '/api/agents/'.rawurlencode($agentId).'/versions')['data']['versions'] ?? [];
    }

    /** GET /api/agents/{id}/versions/{version}/diff (`diffAgentVersion`): what it changed from the one before, as display rows. */
    public function agentVersionChanges(string $key, string $agentId, int $version): array
    {
        return $this->send('GET', $key, '/api/agents/'.rawurlencode($agentId).'/versions/'.$version.'/diff')['data']['changes'] ?? [];
    }

    /** GET /api/agents/{id}/versions/{version}/perf (`getAgentVersionPerf`): how calls went while it was live, beside the one before. Holds Telenow's charge to the operator. */
    public function agentVersionResults(string $key, string $agentId, int $version): array
    {
        return $this->send('GET', $key, '/api/agents/'.rawurlencode($agentId).'/versions/'.$version.'/perf')['data'] ?? [];
    }

    /** POST /api/agents/{id}/versions/{version}/restore (`restoreAgentVersion`): saved as a new version, or none if nothing differs. Answers the agent. */
    public function restoreAgentVersion(string $key, string $agentId, int $version): array
    {
        return $this->send('POST', $key, '/api/agents/'.rawurlencode($agentId).'/versions/'.$version.'/restore');
    }

    /** DELETE /api/agents/{id} (`deleteAgent`). An agent already gone answers 404. */
    public function deleteAgent(string $key, string $agentId): array
    {
        return $this->send('DELETE', $key, '/api/agents/'.rawurlencode($agentId));
    }

    /** POST /api/sessions/init-web-call (`initWebCall`): a browser call's session, `data` of the answer. */
    public function initWebCall(string $key, array $body): array
    {
        return $this->send('POST', $key, '/api/sessions/init-web-call', $body)['data'] ?? [];
    }

    /**
     * POST /api/sessions/initiate-call (`initiateCall`): an agent calls one number now. Sent with an Idempotency-Key, so a try after a lost answer gets the first answer and
     * places no second call (recorded: the same key and body answer the same call; the same key with another body is 422).
     */
    public function initiateCall(string $key, array $body, string $idempotencyKey): array
    {
        return $this->send('POST', $key, '/api/sessions/initiate-call', $body, ['Idempotency-Key' => $idempotencyKey])['data'] ?? [];
    }

    /** DELETE /api/sessions/{id} (`endSession`): hang up. Again is 200 while the server still holds it; a call no server holds is 404. */
    public function endSession(string $key, string $sessionId): void
    {
        $this->send('DELETE', $key, '/api/sessions/'.rawurlencode($sessionId));
    }

    /**
     * POST /api/v1/chat (`chatSend`): one visitor message to an agent, and its reply (recorded, contract_k15chat.py): `{sessionId, reply, turn,
     * identifier}`, flat. The first, with no `sessionId`, starts the conversation; `identifier` names the visitor and binds the session to them.
     * A model that fails still answers 200, with an apology as the reply. Refused: 400 an agent not taking calls or an empty message, 403 another
     * visitor's session or a suspended account, 404 an agent or session not there, 409 while a turn is answered, 410 once the session has ended
     * (suspending the account ends it), 429 at the account's calls-at-once limit. Waits config('kit.chat.timeout_seconds'), as a reply may run tools.
     */
    public function chatSend(string $key, array $body): array
    {
        return $this->send('POST', $key, '/api/v1/chat', $body, [], config('kit.chat.timeout_seconds'));
    }

    /** GET /api/v1/chat/{id}/messages (`chatMessages`): the conversation, oldest first, each `{role: user|assistant, content, createdAt}`; 404 for one not this account's. */
    public function chatMessages(string $key, string $sessionId): array
    {
        return $this->send('GET', $key, '/api/v1/chat/'.rawurlencode($sessionId).'/messages')['messages'] ?? [];
    }

    /** POST /api/v1/chat/{id}/end (`chatEnd`): ends it, and Telenow rates it after; again is no error (recorded); 404 for a session that is not a chat. */
    public function chatEnd(string $key, string $sessionId): void
    {
        $this->send('POST', $key, '/api/v1/chat/'.rawurlencode($sessionId).'/end');
    }

    /**
     * GET /api/orgs/{orgId}/memory (`listOrgCallerMemory`): what the account's agents remember about each caller, the most recent 200 (recorded,
     * contract_k17memory.py): `{callerKey, summary, facts, callCount, lastSession, updatedAt, expiresAt, orgId}`, ended ones too. A phone caller's key
     * is their number's digits; a chat visitor's, the identifier it was sent with, lowercased.
     */
    public function callerMemory(string $key, string $orgId): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/memory')['data'] ?? [];
    }

    /** DELETE /api/orgs/{orgId}/memory?caller= (`eraseOrgCallerMemory`): forget one caller; Telenow reads a number however written, a key in any case (recorded). */
    public function forgetCaller(string $key, string $orgId, string $caller): bool
    {
        return ($this->send('DELETE', $key, '/api/orgs/'.rawurlencode($orgId).'/memory?'.http_build_query(['caller' => $caller]))['data']['removed'] ?? false) === true;
    }

    /** POST /api/sessions/{id}/transfer (`transferCall`): hand a live call to a number. Ended is 400, the do-not-call list or consent 403, one it cannot make 502. */
    public function transferCall(string $key, string $sessionId, string $to): array
    {
        return $this->send('POST', $key, '/api/sessions/'.rawurlencode($sessionId).'/transfer', ['to' => $to])['data'] ?? [];
    }

    /**
     * POST /api/sessions/{id}/context (`addContextNote`): tell the agent on a live call something, never as the caller's words; `respond` `when_idle`
     * has it say it at once on a phone call. `{noteId, key, delivery: next_turn|held|speaking_now}`; refusals as recorded (contract_k22notes.py):
     * 400 `empty`, 404, 409 `not_live` / `no_agent` / `engine_unsupported`, 413 `too_large` with `maxChars`.
     */
    public function addContextNote(string $key, string $sessionId, string $text, bool $sayNow): array
    {
        return $this->send('POST', $key, '/api/sessions/'.rawurlencode($sessionId).'/context', ['text' => $text, 'respond' => $sayNow ? 'when_idle' : 'none'])['data'] ?? [];
    }

    /** GET /api/agents/live-calls (`countLiveCalls`): calls on now, `counts` by Telenow's agent id (a browser call's is its own stand-in agent) and `total`. */
    public function liveCalls(string $key): array
    {
        return $this->send('GET', $key, '/api/agents/live-calls')['data'] ?? [];
    }

    /**
     * POST /api/v1/hooks (`createHook`), with the operator's key: an endpoint on the operator's org. With no agent it is
     * org-wide, so it also gets every sub-account's events, stamped `subAccountId`. The signing secret is answered only here.
     */
    public function createHook(string $operatorKey, array $body): array
    {
        return $this->send('POST', $operatorKey, '/api/v1/hooks', $body);
    }

    /** GET /api/v1/hooks?source= (`listHooks`): the operator's ACTIVE endpoints with that label; a disabled one is not listed. */
    public function hooks(string $operatorKey, string $source): array
    {
        return $this->send('GET', $operatorKey, '/api/v1/hooks?'.http_build_query(['source' => $source]))['hooks'] ?? [];
    }

    /** DELETE /api/v1/hooks/{id} (`deleteHook`): Telenow stops sending to it. */
    public function deleteHook(string $operatorKey, string $hookId): array
    {
        return $this->send('DELETE', $operatorKey, '/api/v1/hooks/'.rawurlencode($hookId));
    }

    /** GET /api/orgs/{orgId}/webhooks (`listWebhookEndpoints`): the account's active endpoints. One Telenow has stopped is no longer listed. */
    public function webhooks(string $key, string $orgId): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/webhooks')['data']['endpoints'] ?? [];
    }

    /** POST /api/orgs/{orgId}/webhooks (`createWebhookEndpoint`): `data` of the answer, with `signing_secret` this once. */
    public function createWebhook(string $key, string $orgId, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/webhooks', $body)['data'] ?? [];
    }

    /** DELETE /api/orgs/{orgId}/webhooks/{id} (`disableWebhookEndpoint`). */
    public function disableWebhook(string $key, string $orgId, string $id): array
    {
        return $this->send('DELETE', $key, '/api/orgs/'.rawurlencode($orgId).'/webhooks/'.rawurlencode($id));
    }

    /** GET /api/orgs/{orgId}/webhooks/{id}/deliveries (`listWebhookDeliveries`): the latest tries, newest first. */
    public function webhookDeliveries(string $key, string $orgId, string $id, int $limit): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/webhooks/'.rawurlencode($id).'/deliveries?limit='.$limit)['data']['deliveries'] ?? [];
    }

    /** POST /api/orgs/{orgId}/webhooks/{id}/test (`sendWebhookTest`): a sample of one event. */
    public function testWebhook(string $key, string $orgId, string $id, string $event): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/webhooks/'.rawurlencode($id).'/test', ['eventType' => $event]);
    }

    /** POST /api/orgs/{orgId}/webhooks/{id}/deliveries/{deliveryId}/replay (`replayWebhookDelivery`): one that has finished, sent again. */
    public function replayWebhookDelivery(string $key, string $orgId, string $id, string $deliveryId, string $idempotencyKey): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/webhooks/'.rawurlencode($id).'/deliveries/'.rawurlencode($deliveryId).'/replay', [], ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * GET /api/orgs/{orgId}/calls (`listCalls`) in feed mode — `$position` is the start instant or the cursor: every
     * call created or changed after it, oldest change first, and `nextCursor` to resume from. `data` of the answer.
     */
    public function callsFeed(string $key, string $orgId, array $position): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/calls?'.http_build_query($position))['data'] ?? [];
    }

    /** GET /api/orgs/{orgId}/calls?status=active (`listCalls`): the account's calls on now, newest first, at most `$limit` (Telenow clamps it to 1–200), each a row as the feed lists it. */
    public function activeCalls(string $key, string $orgId, int $limit): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/calls?'.http_build_query(['status' => 'active', 'limit' => $limit]))['data']['calls'] ?? [];
    }

    /**
     * POST /api/orgs/{orgId}/calls/{id}/listen (`listenToCall`): a one-time ticket, good for 30 s, and the socket that streams the live call's sound with it (`wsUrl`). 404 for a call not the account's, 409 for one not live.
     * The socket sends each 20 ms of each side as a binary frame: a byte for the side (0 the customer, 1 the agent or the person in the browser), then 8 kHz μ-law (recorded, contract_k10listen.py).
     */
    public function listenToCall(string $key, string $orgId, string $callId): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/calls/'.rawurlencode($callId).'/listen')['data'] ?? [];
    }

    /**
     * POST /api/orgs/{orgId}/voices/clone-preflight (`checkVoiceCloning`): whether a provider can clone a voice on Telenow's keys for the account, `data` of the answer
     * (`supported`, and `detail` when not). 400 for a provider Telenow has no key for or does not know (recorded).
     */
    public function checkVoiceCloning(string $key, string $orgId, string $provider): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/voices/clone-preflight', ['provider' => $provider])['data'] ?? [];
    }

    /** POST /api/orgs/{orgId}/voices (`createVoiceProfile`): a voice profile of a named person whose signed release the account holds; the profile (`data`). */
    public function createVoiceProfile(string $key, string $orgId, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/voices', $body)['data'] ?? [];
    }

    /**
     * POST /api/orgs/{orgId}/voices/{id}/sample (`uploadVoiceSample`, multipart): the sample, streamed from the request; the profile (`data`). Telenow reads it with ffmpeg and refuses
     * one it cannot read or under 3 s (400), over 25 MB (413), recorded.
     *
     * @param  resource  $file
     */
    public function uploadVoiceSample(string $key, string $orgId, string $profileId, $file, string $filename, string $type, array $fields): array
    {
        $base = rtrim((string) config('kit.telenow.base_url'), '/');
        try {
            $request = Http::baseUrl($base)
                ->timeout(config('kit.telenow.timeout_seconds'))
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $key, 'User-Agent' => 'voice-kit/'.config('kit.version')])
                ->attach('file', $file, $filename, ['Content-Type' => $type]);
            foreach ($fields as $name => $value) {
                $request = $request->attach($name, (string) $value);
            }
            $response = $request->post('/api/orgs/'.rawurlencode($orgId).'/voices/'.rawurlencode($profileId).'/sample');
        } catch (ConnectionException $e) {
            throw new TelenowError(0, "Could not reach {$base}: {$e->getMessage()}");
        }
        $json = $response->json();
        if ($response->failed() || ! is_array($json)) {
            throw new TelenowError($response->status(), is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : trim(mb_substr($response->body(), 0, 200)));
        }

        return $json['data'] ?? [];
    }

    /** POST /api/orgs/{orgId}/voices/{id}/clone (`cloneVoice`): the profile's voice cloned into a provider on Telenow's keys, at once; the clone (`data`, status `ready`). */
    public function cloneVoice(string $key, string $orgId, string $profileId, string $provider): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/voices/'.rawurlencode($profileId).'/clone', ['provider' => $provider])['data'] ?? [];
    }

    /** POST /api/orgs/{orgId}/voices/{id}/clone/{provider}/remove (`removeVoiceClone`): the clone deleted at the provider and at Telenow. Telenow wants a JSON body, so `{}` is sent. */
    public function removeVoiceClone(string $key, string $orgId, string $profileId, string $provider): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/voices/'.rawurlencode($profileId).'/clone/'.rawurlencode($provider).'/remove', []);
    }

    /** DELETE /api/orgs/{orgId}/voices/{id} (`deleteVoiceProfile`): the profile, its sample and its clones deleted (at the provider too, for clones on Telenow's keys). */
    public function deleteVoiceProfile(string $key, string $orgId, string $profileId): array
    {
        return $this->send('DELETE', $key, '/api/orgs/'.rawurlencode($orgId).'/voices/'.rawurlencode($profileId));
    }

    /**
     * POST /api/agents/preflight (`preflightVoiceStack`) with only the agent: each part of its saved stack checked by a real connection (a few tokens spent on a language
     * model), `data.reports`. Throttled per account (429, retryAfter 3 s); 404 for an agent not the account's; a part with no check of its own is `skipped` (recorded).
     */
    public function checkVoiceStack(string $key, string $agentId): array
    {
        return $this->send('POST', $key, '/api/agents/preflight', ['agentId' => $agentId])['data'] ?? [];
    }

    /** GET /api/orgs/{orgId}/calls/{id} (`getCall`): the call with its transcript, recordings and `completeness`. */
    public function call(string $key, string $orgId, string $callId): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/calls/'.rawurlencode($callId));
    }

    /** GET /api/orgs/{orgId}/analysis/result/{sessionId} (`getCallAnalysis`): `data` is null when there is none. */
    public function callAnalysis(string $key, string $orgId, string $callId): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/analysis/result/'.rawurlencode($callId));
    }

    /**
     * GET /api/orgs/{orgId}/recordings/{id}/file (`downloadRecording`): the audio, streamed — never held whole in memory.
     *
     * @return array{type: string, stream: \Psr\Http\Message\StreamInterface}
     */
    public function recording(string $key, string $orgId, string $recordingId): array
    {
        $base = rtrim((string) config('kit.telenow.base_url'), '/');
        try {
            $response = Http::baseUrl($base)
                ->timeout(config('kit.telenow.timeout_seconds'))
                ->withOptions(['stream' => true])
                ->withHeaders(['X-API-Key' => $key, 'User-Agent' => 'voice-kit/'.config('kit.version')])
                ->get('/api/orgs/'.rawurlencode($orgId).'/recordings/'.rawurlencode($recordingId).'/file');
        } catch (ConnectionException $e) {
            throw new TelenowError(0, "Could not reach {$base}: {$e->getMessage()}");
        }
        if ($response->failed()) {
            $json = $response->json();
            throw new TelenowError($response->status(), is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : 'The recording could not be read.');
        }

        return ['type' => $response->header('Content-Type') ?: 'application/octet-stream', 'stream' => $response->toPsrResponse()->getBody()];
    }

    /** GET /api/orgs/{orgId}/calls/{id}/cost (`getCallCost`): what Telenow charged for the call, or null until it is rated. */
    public function callCost(string $key, string $orgId, string $callId): ?array
    {
        $data = $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/calls/'.rawurlencode($callId).'/cost')['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /** GET /api/v1/sub-accounts/{id}/usage (`getSubAccountUsage`): its spend, `totalSpendUsd` the lifetime total. */
    public function subAccountUsage(string $operatorKey, string $subAccountId): array
    {
        return $this->send('GET', $operatorKey, '/api/v1/sub-accounts/'.rawurlencode($subAccountId).'/usage');
    }

    /** PATCH /api/v1/sub-accounts/{id} (`updateSubAccount`): its name or limits; fields not sent are unchanged. */
    public function updateSubAccount(string $operatorKey, string $subAccountId, array $body): array
    {
        return $this->send('PATCH', $operatorKey, '/api/v1/sub-accounts/'.rawurlencode($subAccountId), $body);
    }

    /** POST /api/orgs/{orgId}/dnc (`addDnc`): the entry — the existing one, with the new reason, when it was already on. */
    public function addDnc(string $key, string $orgId, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/dnc', $body);
    }

    /** DELETE /api/orgs/{orgId}/dnc/{id} (`removeDnc`): 404 when it is gone already. */
    public function removeDnc(string $key, string $orgId, string $entryId): array
    {
        return $this->send('DELETE', $key, '/api/orgs/'.rawurlencode($orgId).'/dnc/'.rawurlencode($entryId));
    }

    /**
     * GET /api/orgs/{orgId}/dnc (`listDnc`) in feed mode — `$position` is the start instant or the cursor: every change
     * after it, oldest first, deletions included, and `nextCursor`. `data` of the answer.
     */
    public function dncFeed(string $key, string $orgId, array $position): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/dnc?'.http_build_query($position))['data'] ?? [];
    }

    /** POST /api/orgs/{orgId}/dnc/import (`importDnc`): a CSV, sent as Telenow takes it (multipart); answers how many were added and skipped. */
    public function importDnc(string $key, string $orgId, string $csv): array
    {
        $base = rtrim((string) config('kit.telenow.base_url'), '/');
        try {
            $response = Http::baseUrl($base)
                ->timeout(config('kit.telenow.timeout_seconds'))
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $key, 'User-Agent' => 'voice-kit/'.config('kit.version')])
                ->attach('file', $csv, 'dnc.csv', ['Content-Type' => 'text/csv'])
                ->post('/api/orgs/'.rawurlencode($orgId).'/dnc/import');
        } catch (ConnectionException $e) {
            throw new TelenowError(0, "Could not reach {$base}: {$e->getMessage()}");
        }
        $json = $response->json();
        if ($response->failed() || ! is_array($json)) {
            throw new TelenowError($response->status(), is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : trim(mb_substr($response->body(), 0, 200)));
        }

        return $json['data'] ?? [];
    }

    /** POST /api/orgs/{orgId}/knowledge-bases (`createKnowledgeBase`): the whole answer (`data` is the base). */
    public function createKnowledgeBase(string $key, string $orgId, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases', $body);
    }

    /** DELETE /api/orgs/{orgId}/knowledge-bases/{kbId} (`deleteKnowledgeBase`): every agent stops answering from it. */
    public function deleteKnowledgeBase(string $key, string $orgId, string $kbId): array
    {
        return $this->send('DELETE', $key, '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases/'.rawurlencode($kbId));
    }

    /** GET /api/orgs/{orgId}/knowledge-bases (`listKnowledgeBases`): the account's bases (`data.knowledgeBases`). */
    public function knowledgeBases(string $key, string $orgId): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases')['data']['knowledgeBases'] ?? [];
    }

    /**
     * GET /api/orgs/{orgId}/knowledge-bases/{kbId}/documents?updatedAfter (`listKnowledgeDocuments`): every document of
     * the base changed after an instant, deletions included (`data.documents`).
     */
    public function knowledgeDocumentsChangedAfter(string $key, string $orgId, string $kbId, string $after): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases/'.rawurlencode($kbId).'/documents?'
            .http_build_query(['updatedAfter' => $after]))['data']['documents'] ?? [];
    }

    /**
     * GET …/documents/{docId}/dataset (`getKnowledgeTable`): a table document's `{columns[{name, sql_name, type}], rowCount, colCount, format,
     * inlined, fuzzyTruncated, fuzzyRowCap}`; null for a text document, one that is not there or is deleted (recorded, contract_k19tables.py).
     */
    public function knowledgeTable(string $key, string $orgId, string $kbId, string $docId): ?array
    {
        return $this->send('GET', $key, self::documentPath($orgId, $kbId, $docId).'/dataset')['data'] ?? null;
    }

    /** GET …/documents/{docId}/rows (`listKnowledgeTableRows`): `{columns, rows (keyed by sql_name), rowCount, limit, offset}`, or null as knowledgeTable. */
    public function knowledgeTableRows(string $key, string $orgId, string $kbId, string $docId, int $offset, int $limit): ?array
    {
        return $this->send('GET', $key, self::documentPath($orgId, $kbId, $docId).'/rows?'.http_build_query(['limit' => $limit, 'offset' => $offset]))['data'] ?? null;
    }

    /**
     * PATCH …/documents/{docId}/dataset (`setKnowledgeColumnTypes`): `types` by sql_name. A type that is not one, or a column the table does not have,
     * is 400; a document that is not a table is 404 (recorded).
     *
     * @param  array<string, string>  $types
     */
    public function setKnowledgeColumnTypes(string $key, string $orgId, string $kbId, string $docId, array $types): void
    {
        $this->send('PATCH', $key, self::documentPath($orgId, $kbId, $docId).'/dataset', ['types' => $types]);
    }

    private static function documentPath(string $orgId, string $kbId, string $docId): string
    {
        return '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases/'.rawurlencode($kbId).'/documents/'.rawurlencode($docId);
    }

    /** POST …/documents (`addKnowledgeText`): the whole answer (`data` is the document). */
    public function addKnowledgeText(string $key, string $orgId, string $kbId, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases/'.rawurlencode($kbId).'/documents', $body);
    }

    /** POST …/documents/url (`addKnowledgeUrl`): Telenow fetches the page now; the whole answer. */
    public function addKnowledgeUrl(string $key, string $orgId, string $kbId, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases/'.rawurlencode($kbId).'/documents/url', $body);
    }

    /**
     * POST …/documents/upload (`uploadKnowledgeFile`, multipart): the file streamed from where it waits; the whole answer.
     *
     * @param  resource  $file
     */
    public function uploadKnowledgeFile(string $key, string $orgId, string $kbId, $file, string $filename, ?string $title): array
    {
        $base = rtrim((string) config('kit.telenow.base_url'), '/');
        try {
            $request = Http::baseUrl($base)
                ->timeout(config('kit.telenow.timeout_seconds'))
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $key, 'User-Agent' => 'voice-kit/'.config('kit.version')])
                ->attach('file', $file, $filename);
            $response = ($title === null ? $request : $request->attach('title', $title))
                ->post('/api/orgs/'.rawurlencode($orgId).'/knowledge-bases/'.rawurlencode($kbId).'/documents/upload');
        } catch (ConnectionException $e) {
            throw new TelenowError(0, "Could not reach {$base}: {$e->getMessage()}");
        }
        $json = $response->json();
        if ($response->failed() || ! is_array($json)) {
            throw new TelenowError($response->status(), is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : trim(mb_substr($response->body(), 0, 200)));
        }

        return $json;
    }

    /** POST …/documents/{docId}/retry (`retryKnowledgeDocument`): embed a failed document again; the whole answer. */
    public function retryKnowledgeDocument(string $key, string $orgId, string $kbId, string $docId): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases/'.rawurlencode($kbId).'/documents/'.rawurlencode($docId).'/retry');
    }

    /** DELETE …/documents/{docId} (`deleteKnowledgeDocument`). */
    public function deleteKnowledgeDocument(string $key, string $orgId, string $kbId, string $docId): array
    {
        return $this->send('DELETE', $key, '/api/orgs/'.rawurlencode($orgId).'/knowledge-bases/'.rawurlencode($kbId).'/documents/'.rawurlencode($docId));
    }

    /**
     * PUT /api/orgs/{orgId}/agents/{agentId}/knowledge-bases (`setAgentKnowledgeBases`): the agent's whole set of bases
     * in one step (an empty list detaches them all).
     *
     * @param  list<string>  $kbIds
     */
    public function setAgentKnowledgeBases(string $key, string $orgId, string $agentId, array $kbIds): array
    {
        return $this->send('PUT', $key, '/api/orgs/'.rawurlencode($orgId).'/agents/'.rawurlencode($agentId).'/knowledge-bases',
            ['knowledgeBases' => array_map(fn (string $id) => ['kbId' => $id], $kbIds)]);
    }

    /** POST /api/orgs/{orgId}/campaigns (`createCampaign`): a draft; the whole answer (`data` is the campaign). */
    public function createCampaign(string $key, string $orgId, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns', $body);
    }

    /** GET /api/orgs/{orgId}/campaigns/{id} (`getCampaign`): `data`, with why it is not dialling (`dial_block`). */
    public function campaign(string $key, string $orgId, string $id): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id))['data'] ?? [];
    }

    /** POST …/campaigns/{id}/{start|pause|cancel} (`startCampaign`, `pauseCampaign`, `cancelCampaign`). */
    public function campaignAction(string $key, string $orgId, string $id, string $action): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id).'/'.rawurlencode($action));
    }

    /** DELETE …/campaigns/{id} (`deleteCampaign`): a running one is stopped first. */
    public function deleteCampaign(string $key, string $orgId, string $id): array
    {
        return $this->send('DELETE', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id));
    }

    /** PATCH …/campaigns/{id}/schedule (`setCampaignSchedule`): the window is always replaced, null clears the caller id, the rest are read as sent. */
    public function setCampaignSchedule(string $key, string $orgId, string $id, array $body): array
    {
        return $this->send('PATCH', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id).'/schedule', $body);
    }

    /** PATCH …/campaigns/{id}/concurrency (`setCampaignConcurrency`): answers the campaign. */
    public function setCampaignConcurrency(string $key, string $orgId, string $id, int $concurrency): array
    {
        return $this->send('PATCH', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id).'/concurrency', ['concurrency' => $concurrency]);
    }

    /** POST …/campaigns/{id}/retry-failed (`retryFailedTargets`): how many it took back (`data`). */
    public function retryFailedTargets(string $key, string $orgId, string $id, string $scope): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id).'/retry-failed', ['scope' => $scope])['data'] ?? [];
    }

    /** GET …/campaigns/{id}/analytics (`getCampaignAnalytics`): its figures (`data`), what it charges the operator among them. */
    public function campaignAnalytics(string $key, string $orgId, string $id): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id).'/analytics')['data'] ?? [];
    }

    /** POST …/consent (`recordConsent`): answers the record, its evidence with a server block added. It takes the same one twice. */
    public function recordConsent(string $key, string $orgId, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/consent', $body);
    }

    /** GET …/consent/{phone} (`getConsentHistory`): a number's records, at most 500, newest first (`data.records`). */
    public function consentHistory(string $key, string $orgId, string $phone): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/consent/'.rawurlencode($phone))['data']['records'] ?? [];
    }

    /** POST …/consent/{phone}/revoke (`revokeConsent`): withdraws all of a number's, puts it on the do-not-call list and drops its waiting calls. Asking again is harmless. It needs a body. */
    public function revokeConsent(string $key, string $orgId, string $phone, array $body): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/consent/'.rawurlencode($phone).'/revoke', $body)['data'] ?? [];
    }

    /** GET …/consent/coverage (`getConsentCoverage`): how many waiting calls have consent, and the rule in force (`data`). */
    public function consentCoverage(string $key, string $orgId): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/consent/coverage')['data'] ?? [];
    }

    /** PUT …/consent/enforcement (`setConsentEnforcement`): `off` is 403 while the account billing it requires it. */
    public function setConsentEnforcement(string $key, string $orgId, string $mode): array
    {
        return $this->send('PUT', $key, '/api/orgs/'.rawurlencode($orgId).'/consent/enforcement', ['mode' => $mode])['data'] ?? [];
    }

    /** GET …/campaigns/{id}/reach (`getCampaignReach`): how many pending targets can be dialled (`data`). */
    public function campaignReach(string $key, string $orgId, string $id): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id).'/reach')['data'] ?? [];
    }

    /** GET …/campaigns/{id}/variables (`listCampaignVariables`): the variables its agent uses (`data.variables`). */
    public function campaignVariables(string $key, string $orgId, string $id): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/campaigns/'.rawurlencode($id).'/variables')['data']['variables'] ?? [];
    }

    /**
     * POST /api/v1/campaigns/{id}/targets (`pushCampaignTargetsV1`): up to 1,000 people, each with the kit's own `id` (a
     * duplicate id is skipped); answers the counts.
     */
    public function pushCampaignTargets(string $key, string $id, array $body): array
    {
        return $this->send('POST', $key, '/api/v1/campaigns/'.rawurlencode($id).'/targets', $body);
    }

    /**
     * GET /api/v1/campaigns/{id}/results (`getCampaignResultsV1`): finished targets in the order they finished, from a
     * cursor or an instant; the whole answer ({results, nextCursor, nextSince}).
     *
     * @param  array{cursor?: string, since?: string}  $position
     */
    public function campaignResults(string $key, string $id, array $position): array
    {
        return $this->send('GET', $key, '/api/v1/campaigns/'.rawurlencode($id).'/results?'.http_build_query($position));
    }

    /** GET /api/voice/numbers/search (`searchNumbers`): numbers for sale, priced in USD; `data` ({provider, numbers}). */
    public function searchNumbers(string $key, array $query): array
    {
        return $this->send('GET', $key, '/api/voice/numbers/search?'.http_build_query($query))['data'] ?? [];
    }

    /** POST /api/voice/numbers/purchase (`purchaseNumber`): charged at once to the operator's account; the whole answer. */
    public function purchaseNumber(string $key, array $body, string $idempotencyKey): array
    {
        return $this->send('POST', $key, '/api/voice/numbers/purchase', $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /** POST /api/voice/numbers/{id}/assign-agent (`assignNumberAgent`): the number, as stored. */
    public function assignNumberAgent(string $key, string $numberId, string $agentId): array
    {
        return $this->send('POST', $key, '/api/voice/numbers/'.rawurlencode($numberId).'/assign-agent', ['agentId' => $agentId]);
    }

    /** DELETE /api/voice/numbers/{id}/agent (`unassignNumberAgent`): the number, as stored. */
    public function unassignNumberAgent(string $key, string $numberId): array
    {
        return $this->send('DELETE', $key, '/api/voice/numbers/'.rawurlencode($numberId).'/agent');
    }

    /**
     * POST /api/voice/numbers/{id}/pool (`setNumberPool`): whether calls to the number ask the account's routing endpoint who answers ("pool");
     * the number, as stored (`is_pool`). Refused (400) for a number with no agent, as recorded (contract_k26routing.py): its agent is the fallback.
     */
    public function setNumberPool(string $key, string $numberId, bool $routed): array
    {
        return $this->send('POST', $key, '/api/voice/numbers/'.rawurlencode($numberId).'/pool', ['isPool' => $routed]);
    }

    /**
     * POST /api/voice/numbers/{id}/default-outbound (`setDefaultOutboundNumber`): whether the number is its agent's caller id when a call names
     * none; the number, as stored. Setting one clears the agent's other (recorded: the other's `updated_at` moves too); refused (400) for a number
     * with no agent. Telenow clears it itself whenever the number's agent is set again, even to the same agent (recorded).
     */
    public function setDefaultOutboundNumber(string $key, string $numberId, bool $isDefault): array
    {
        return $this->send('POST', $key, '/api/voice/numbers/'.rawurlencode($numberId).'/default-outbound', ['isDefault' => $isDefault]);
    }

    /**
     * POST /api/voice/numbers/{id}/inbound-action (`setNumberInboundAction`): what a call to the number does while no agent answers it (`speak`,
     * `reject`, `no-answer`; null for the account's default, which is `speak`); the number, as stored. Refused (409) for a number with an agent;
     * cleared by Telenow when one is set (recorded).
     */
    public function setNumberInboundAction(string $key, string $numberId, ?string $action): array
    {
        return $this->send('POST', $key, '/api/voice/numbers/'.rawurlencode($numberId).'/inbound-action', ['action' => $action]);
    }

    /** GET /api/voice/numbers/{id} (`getNumber`): one number, with its rent in USD. */
    public function getNumber(string $key, string $numberId): array
    {
        return $this->send('GET', $key, '/api/voice/numbers/'.rawurlencode($numberId));
    }

    /**
     * GET /api/voice/numbers/reclaimable (`listReclaimableNumbers`): numbers Telenow gave up for the account (not ones it released itself) that
     * the carrier may still hold, each with its deadline, what it was set up for and its last prices; `data.numbers`.
     */
    public function reclaimableNumbers(string $key): array
    {
        return $this->send('GET', $key, '/api/voice/numbers/reclaimable')['data']['numbers'] ?? [];
    }

    /**
     * POST /api/voice/numbers/{id}/reclaim (`reclaimNumber`): ask for a given-up number back, bought again at once and charged to the operator's
     * account (`status` restored: a NEW number, set up as it was; its answer's `number` is the row before that set-up, so it is read again), or a
     * request Telenow's staff finish by hand before the deadline (`status` requested); `data`. Refused: 403 the operator's account cannot pay, 400 the
     * hold ended or the account released it, 409 already back, 404 not the account's or not given up (all recorded).
     */
    public function reclaimNumber(string $key, string $numberId): array
    {
        return $this->send('POST', $key, '/api/voice/numbers/'.rawurlencode($numberId).'/reclaim')['data'] ?? [];
    }

    /** GET /api/orgs/{orgId}/inbound-routing (`getInboundRouting`): the account's routing endpoint, or null; never the secret. */
    public function inboundRouting(string $key, string $orgId): ?array
    {
        $data = $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/inbound-routing')['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /**
     * PUT /api/orgs/{orgId}/inbound-routing (`setInboundRouting`): where Telenow asks; `signingSecret` in full only on the answer that made one
     * (the first, or `rotateSecret`). A timeout not sent is reset to 1200 and `enabled` not sent is kept (recorded), so both are always sent.
     */
    public function setInboundRouting(string $key, string $orgId, string $url, int $timeoutMs, bool $rotateSecret): array
    {
        return $this->send('PUT', $key, '/api/orgs/'.rawurlencode($orgId).'/inbound-routing',
            ['url' => $url, 'timeoutMs' => $timeoutMs, 'enabled' => true, 'rotateSecret' => $rotateSecret])['data'] ?? [];
    }

    /**
     * POST /api/orgs/{orgId}/inbound-routing/test (`testInboundRouting`): Telenow asks the endpoint about a pretend call to a routed number and says
     * whether it answered, how long it took, the action and why there is none (its own words). Never what the answer held.
     */
    public function testInboundRouting(string $key, string $orgId, string $toNumber): array
    {
        return $this->send('POST', $key, '/api/orgs/'.rawurlencode($orgId).'/inbound-routing/test', ['toNumber' => $toNumber])['data'] ?? [];
    }

    /** DELETE /api/voice/numbers/{id} (`releaseNumber`): back to the carrier, rent stops; 404 when gone already. */
    public function releaseNumber(string $key, string $numberId): array
    {
        return $this->send('DELETE', $key, '/api/voice/numbers/'.rawurlencode($numberId));
    }

    /** GET /api/voice/numbers?updatedAfter= (`listNumbers`): every number changed after the instant, released ones too. */
    public function numbersChangedAfter(string $key, string $after): array
    {
        return $this->send('GET', $key, '/api/voice/numbers?'.http_build_query(['updatedAfter' => $after]))['data']['numbers'] ?? [];
    }

    /**
     * GET /api/orgs/{orgId}/billing/ledger (`listLedger`) in feed mode, with a customer's own key: the entries it caused
     * (calls, number setup and rent) after the position, oldest first, and `nextCursor`. `data` of the answer.
     */
    public function ledgerFeed(string $key, string $orgId, array $position): array
    {
        return $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/billing/ledger?'.http_build_query($position))['data'] ?? [];
    }

    /**
     * PUT /api/orgs/{orgId}/billing/spend-alert (`setSpendAlert`): the level, in USD, at or below which the account's spend state is `low` (null clears
     * it); the spend state it gives now, `data`. A top-level account only: a sub-account, or another org, is refused 403; a negative, text or unknown
     * field 400 (recorded, contract_k29billing.py). The state is `low` while `headroomUsd` is at or below it, so a postpaid account with no cap (no
     * headroom) is never low (recorded).
     */
    public function setSpendAlert(string $key, string $orgId, ?float $thresholdUsd): array
    {
        return $this->send('PUT', $key, '/api/orgs/'.rawurlencode($orgId).'/billing/spend-alert', ['thresholdUsd' => $thresholdUsd])['data'] ?? [];
    }

    /**
     * GET /api/orgs/{orgId}/billing/invoices (`listInvoices`): the account's invoices, newest period first, drafts too, each `{invoice, lines}`; `data`.
     * Telenow invoices a postpaid account only (none for a prepaid one, recorded). The lines are net; an invoice's tax (GST, for an Indian account) is
     * in its total and in no field of its own.
     */
    public function invoices(string $key, string $orgId): array
    {
        $data = $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/billing/invoices')['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * GET /api/catalog/openrouter-models (`listOpenRouterModels`): OpenRouter's public model list as Telenow reads it, each `{id, name, textToText}`;
     * empty when OpenRouter cannot be reached. An agent may name any OpenRouter model id: Telenow takes even one that is not listed (recorded).
     */
    public function openRouterModels(string $key): array
    {
        $data = $this->send('GET', $key, '/api/catalog/openrouter-models')['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * GET /api/catalog/openrouter-price (`getOpenRouterPrice`): what Telenow charges per million tokens for an OpenRouter model (its margin in), `data`:
     * `{found: false}` for one with no price. An estimate: Telenow charges what OpenRouter reports for each request, plus its margin.
     */
    public function openRouterPrice(string $key, string $model): array
    {
        return $this->send('GET', $key, '/api/catalog/openrouter-price?'.http_build_query(['model' => $model]))['data'] ?? ['found' => false];
    }

    /**
     * GET /api/catalog/processing-country (`getProcessingCountry`): the country the key's account's calls are processed for, and where it comes from.
     * A sub-account copies the operator's country when it is made, and keeps it after the operator's changes (recorded).
     */
    public function processingCountry(string $key): array
    {
        return $this->send('GET', $key, '/api/catalog/processing-country')['data'] ?? [];
    }

    /** GET /api/orgs/{orgId}/billing/summary (`getBillingSummary`): the account's billing, `data` of the answer. */
    public function billingSummary(string $key, string $orgId): array
    {
        $data = $this->send('GET', $key, '/api/orgs/'.rawurlencode($orgId).'/billing/summary')['data'] ?? null;
        if (! is_array($data)) {
            throw new TelenowError(200, 'The billing summary had no data.');
        }

        return $data;
    }

    /** @return array{type: string, bytes: string} a short audio answer, held whole (a clip of seconds, not a recording) */
    private function audio(string $key, string $path, array $body): array
    {
        $base = rtrim((string) config('kit.telenow.base_url'), '/');
        try {
            $response = Http::baseUrl($base)
                ->timeout(config('kit.telenow.timeout_seconds'))
                ->withHeaders(['X-API-Key' => $key, 'User-Agent' => 'voice-kit/'.config('kit.version')])
                ->send('POST', $path, ['json' => (object) $body]);
        } catch (ConnectionException $e) {
            throw new TelenowError(0, "Could not reach {$base}: {$e->getMessage()}");
        }
        if ($response->failed()) {
            $json = $response->json();
            throw new TelenowError($response->status(), is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : 'The clip could not be made.');
        }

        return ['type' => $response->header('Content-Type') ?: 'application/octet-stream', 'bytes' => $response->body()];
    }

    /** @param array<string, string> $headers @param ?int $timeout seconds, when not config('kit.telenow.timeout_seconds') */
    private function send(string $method, string $key, string $path, ?array $body = null, array $headers = [], ?int $timeout = null): array
    {
        $response = $this->request($method, $key, $path, $body, $headers, $timeout);
        $json = $response->json();
        if (! is_array($json)) {
            throw new TelenowError($response->status(), 'Telenow did not answer JSON.');
        }

        return $json;
    }

    /** @param array<string, string> $headers @param ?int $timeout seconds, when not config('kit.telenow.timeout_seconds') */
    private function request(string $method, string $key, string $path, ?array $body = null, array $headers = [], ?int $timeout = null): Response
    {
        $base = rtrim((string) config('kit.telenow.base_url'), '/');
        try {
            $response = Http::baseUrl($base)
                ->timeout($timeout ?? config('kit.telenow.timeout_seconds'))
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $key, 'User-Agent' => 'voice-kit/'.config('kit.version')] + $headers)
                ->send($method, $path, $body === null ? [] : ['json' => (object) $body]);
        } catch (ConnectionException $e) {
            throw new TelenowError(0, "Could not reach {$base}: {$e->getMessage()}");
        }
        if ($response->failed()) {
            $json = $response->json();
            $said = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : trim(mb_substr($response->body(), 0, 200));
            throw new TelenowError($response->status(), $said, is_array($json) ? $json : []);
        }

        return $response;
    }
}
