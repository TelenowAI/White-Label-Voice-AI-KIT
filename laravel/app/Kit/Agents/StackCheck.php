<?php

namespace App\Kit\Agents;

use App\Kit\Console\ServicePause;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;

/**
 * Checking an agent's voice setup (an agent's page → Check the voice setup): Telenow connects to each provider of the agent's saved stack with the account's own key (its
 * preflight) and says, of each part, ok, failed, or not checked (a provider it has no check for), and whose problem a failure is. The kit says each in its own words, never a
 * provider's message nor which key was used, and keeps the last check on the agent.
 *
 * Telenow takes one check an account every 3 s (429 with retryAfter, recorded); a check spends a few tokens of a language model, so none while the service is paused for the
 * account. An agent on a realtime voice (AgentRealtime) has that part too, never checked: Telenow's check probes the pipeline only, whatever the engine (recorded).
 * Not here: the page (AgentsController), the parts' own settings (AgentProviderSettings).
 */
final class StackCheck
{
    public function __construct(private readonly TelenowClient $telenow, private readonly ServicePause $pause, private readonly AgentSettings $settings) {}

    /** @return array{checkedAt: string, parts: list<array{part: string, status: string, reason: ?string}>} */
    public function check(Agent $agent): array
    {
        if ($agent->telenow_id === null) {
            throw new AgentRefused(409, __('agents.not_ready'));
        }
        $paused = $this->pause->for($agent->workspace);
        if ($paused !== null) {
            throw new AgentRefused(409, $paused['message']);
        }
        try {
            $answer = $this->telenow->checkVoiceStack((string) $agent->workspace->telenow_key, $agent->telenow_id);
        } catch (TelenowError $e) {
            throw match (true) {
                $e->status === 429 => new AgentRefused(429, __('agents.check.too_soon')),
                $e->status === 404 => new AgentRefused(409, __('agents.not_ready')),
                $e->status >= 400 && $e->status < 500 => new AgentRefused(409, __('agents.check.refused')),
                default => new AgentRefused(503, __('agents.check.unavailable')),
            };
        }
        $parts = $this->parts(is_array($answer['reports'] ?? null) ? $answer['reports'] : []);
        // Telenow probes the pipeline only, whatever the engine (recorded): an agent on a realtime voice says that part is not checked.
        if (($this->settings->of($agent)[AgentRealtime::KEY] ?? null) !== null) {
            $parts[] = ['part' => config('kit.stack_check.realtime_part'), 'status' => 'not_checked', 'reason' => __('agents.check.realtime_not_checked')];
        }
        $check = ['checkedAt' => now()->toIso8601String(), 'parts' => $parts];
        $agent->forceFill(['stack_check' => $check, 'stack_checked_at' => now()])->save();

        return $check;
    }

    /** @return ?array the last check, as check() answered it */
    public function last(Agent $agent): ?array
    {
        return is_array($agent->stack_check) ? $agent->stack_check : null;
    }

    /** Each part of the stack, in the kit's order and words: a part Telenow did not report on was not checked. */
    private function parts(array $reports): array
    {
        $byPart = [];
        foreach ($reports as $r) {
            if (is_array($r) && is_string($r['component'] ?? null)) {
                $byPart[$r['component']] = $r;
            }
        }
        $known = config('kit.stack_check.attributions');

        return array_map(function (string $part) use ($byPart, $known) {
            $r = $byPart[$part] ?? null;
            $status = match ($r['status'] ?? null) {
                'ok' => 'ok',
                'failed' => 'failed',
                default => 'not_checked',
            };
            $whose = in_array($r['attribution'] ?? null, $known, true) ? $r['attribution'] : 'unknown';

            return ['part' => $part, 'status' => $status, 'reason' => match ($status) {
                'failed' => __("agents.check.because.{$whose}"),
                'not_checked' => __('agents.check.not_checked'),
                default => null,
            }];
        }, config('kit.stack_check.parts'));
    }
}
