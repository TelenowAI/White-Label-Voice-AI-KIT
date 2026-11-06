<?php

namespace App\Kit\Agents\Telenow;

use App\Kit\Agents\AgentSettings;
use App\Kit\Outbox\NotYet;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Sends an agent's changed fields to Telenow (only those: Telenow keeps every field it is not sent, metadata.kitId
 * included — recorded by contract_k1agents.py). Its answer's `version` is kept with the agent.
 *
 * Changed settings live in blobs Telenow replaces whole, so they are read fresh at every try: Telenow's blobs now, with only those
 * keys changed, saved only if the agent is still at the version just read. Someone saving in between refuses it with nothing
 * written (recorded by contract_k1settings.py), and the next try starts again from what they saved.
 */
final class UpdateAtTelenow extends AgentWrite
{
    public function send(OutboxEntry $entry): void
    {
        $agent = $this->agent($entry);
        $key = $this->customerKey($agent);
        $body = $entry->body;
        $changed = $body[AgentSettings::CHANGES] ?? null;
        unset($body[AgentSettings::CHANGES]);
        $version = null;
        if (is_array($changed)) {
            $object = $this->manifest->object(self::RESOURCE, 'read', $this->telenow->agentAsStored($key, $agent->telenow_id));
            $version = $this->telenow->agentVersion($key, $agent->telenow_id);
            $body = $this->agentSettings->writes($object, $changed) + $body;
        }
        try {
            $answer = $this->telenow->updateAgent($key, $agent->telenow_id, $body, $version);
        } catch (TelenowError $e) {
            throw $e->status === 409 && $version !== null ? new NotYet($e->getMessage()) : $e;
        }
        $saved = $answer['version'] ?? null;
        $this->synced($agent, $this->manifest->object(self::RESOURCE, 'update', $answer), is_int($saved) ? $saved : null);
    }
}
