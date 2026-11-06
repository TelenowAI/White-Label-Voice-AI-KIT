<?php

namespace App\Kit\Agents;

use App\Kit\Console\ServicePause;
use App\Kit\Telenow\TelenowError;
use App\Kit\Telenow\TelenowClient;
use App\Models\Agent;

/**
 * A browser test call with one of the customer's agents (SELF_HOSTED_KIT_PLAN.md §5 C7): the kit's server starts the
 * session with the customer's own key and answers it to the console, which joins with @telenow/client's `session`
 * option — no credential reaches the browser. Only the session's join fields are answered (Telenow's ready-made embed
 * snippet names its host and is dropped).
 *
 * A call costs money, so none starts while the service is paused for the account (ServicePause). Telenow's refusals
 * come back as the kit's own words, never Telenow's.
 */
final class TestCall
{
    /** The fields a client joins with (TelenowSession in @telenow/client). */
    private const JOIN = ['sessionId', 'transport', 'websocketUrl', 'livekitUrl', 'token', 'room'];

    public function __construct(private readonly TelenowClient $telenow, private readonly ServicePause $pause) {}

    /** @return array<string, string> */
    public function start(Agent $agent): array
    {
        $workspace = $agent->workspace;
        $paused = $this->pause->for($workspace);
        if ($paused !== null) {
            throw new AgentRefused(409, $paused['message']);
        }
        if ($agent->telenow_id === null) {
            throw new AgentRefused(409, __('agents.not_ready'));
        }
        try {
            $session = $this->telenow->initWebCall($workspace->telenow_key, ['agentId' => $agent->telenow_id]);
        } catch (TelenowError $e) {
            throw match (true) {
                $e->status === 429 => new AgentRefused(429, __('agents.test_busy')),
                $e->status === 403 => new AgentRefused(409, __('console.paused.service_paused')),
                $e->status === 404 => new AgentRefused(409, __('agents.not_ready')),
                default => new AgentRefused(503, __('agents.test_unavailable')),
            };
        }

        return array_filter(array_intersect_key($session, array_flip(self::JOIN)), fn ($v) => is_string($v) && $v !== '');
    }
}
