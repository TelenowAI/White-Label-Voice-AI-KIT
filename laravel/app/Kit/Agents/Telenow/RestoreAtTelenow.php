<?php

namespace App\Kit\Agents\Telenow;

use App\Kit\Agents\AgentSettings;
use App\Kit\Agents\AgentVersions;
use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;

/**
 * Puts an agent back to one of its versions at Telenow, with the customer's key. Telenow writes the version back as it was, keys and all, and a try after a lost answer changes
 * nothing further (recorded: the same state makes no new version). The agent is then read from its answer, and AgentVersions::settle takes off what may not come back.
 */
final class RestoreAtTelenow extends AgentWrite
{
    public function __construct(TelenowClient $telenow, Manifest $manifest, Settings $settings, AgentSettings $agentSettings, private readonly AgentVersions $versions)
    {
        parent::__construct($telenow, $manifest, $settings, $agentSettings);
    }

    public function send(OutboxEntry $entry): void
    {
        $agent = $this->agent($entry);
        $key = $this->customerKey($agent);
        try {
            $answer = $this->telenow->restoreAgentVersion($key, $agent->telenow_id, $entry->body['version']);
        } catch (TelenowError $e) {
            throw $e->status >= 400 && $e->status < 500 ? new TelenowError($e->status, __('agents.version_refused')) : $e;
        }
        $this->synced($agent, $this->manifest->object(self::RESOURCE, 'update', $answer), $this->telenow->agentVersion($key, $agent->telenow_id));
        $this->versions->settle($agent, $entry->body);
    }
}
