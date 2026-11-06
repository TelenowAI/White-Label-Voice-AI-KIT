<?php

namespace App\Kit\Agents;

use App\Kit\Settings\Settings;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The operator's switch for transfers to a person (Admin → Catalog). A transfer is a second phone call the operator pays the
 * carrier for at the carrier's real price, so a customer may only set one up once the operator has said yes (AgentSettings refuses
 * it until then). Turning the switch off takes transfer off every agent that has it: `kit:agents` does so through the same outbox
 * as a customer's own save, at most config('kit.agents.transfer_sweep_per_run') a run, and is left with nothing to do once none
 * are left, so the table is only read when there is something to find.
 *
 * A write Telenow gave up on is tried once per turning off, and the agent then stays counted on the admin page until someone saves
 * that page or retries the agent. A closed customer's agents are left: its key is gone and its calls have ended.
 *
 * Not here: the switch's value (AgentSettings::transferAllowed), writing the tool (AgentSettings::writes).
 */
final class AgentTransfers
{
    /** When the switch was last saved off, while agents may still be holding a transfer. */
    private const SWEEP = 'agents.transfer_sweep_since';

    public const OFF = ['enabled' => false, 'destinations' => [], 'message' => null, 'noAnswerMessage' => null];

    public function __construct(private readonly Settings $settings, private readonly AgentSettings $agentSettings, private readonly Agents $agents) {}

    public function save(bool $allowed): void
    {
        $this->settings->put(AgentSettings::TRANSFER_ALLOWED, $allowed);
        if ($allowed) {
            $this->settings->forget(self::SWEEP);
        } else {
            $this->settings->put(self::SWEEP, now()->toIso8601String());
        }
    }

    /** How many agents have a transfer now: on the page beside the switch. */
    public function stillOn(): int
    {
        return $this->holders()->count();
    }

    /** @return int how many agents it wrote to */
    public function sweep(): int
    {
        $since = $this->settings->get(self::SWEEP);
        if ($since === null) {
            return 0;
        }
        $limit = config('kit.agents.transfer_sweep_per_run');
        $written = 0;
        $waiting = false;
        foreach ($this->holders()->lazyById() as $agent) {
            if ($written >= $limit) {
                return $written;
            }
            $now = $this->agentSettings->of($agent);
            $flow = $this->agentSettings->cleanedFlow($agent);
            if ($now['transfer']['enabled'] || $flow['kept'] !== []) {
                $this->agents->saveChecked($agent, [], ($now['transfer']['enabled'] ? ['transfer' => self::OFF] : []) + ($flow['kept'] === [] ? [] : ['flow' => $flow['flow']]), $now);
                $written++;
                $waiting = true;
            } elseif ($agent->sync_state === 'pending') {
                $waiting = true;
            } elseif ($agent->sync_state === 'failed' && $agent->updated_at->lt(Carbon::parse($since))) {
                $this->agents->retry($agent);
                $written++;
                $waiting = true;
            }
        }
        if (! $waiting) {
            $this->settings->forget(self::SWEEP);
        }

        return $written;
    }

    /** Agents with a transfer in what was last written or what Telenow has: a line in a prompt is stored with its quotes escaped, and cannot match. */
    private function holders(): Builder
    {
        return Agent::query()->whereNull('deleted_at')->whereHas('workspace', fn ($w) => $w->whereNotNull('telenow_key'))
            ->where(fn ($q) => $q->where('telenow_snapshot', 'like', '%"kind":"transfer"%')->orWhere('settings', 'like', '%"transfer":{"enabled":true%'));
    }
}
