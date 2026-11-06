<?php

namespace App\Kit\Agents\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Makes the agent at Telenow with the customer's key. Telenow's create needs a whole provider stack
 * (config('kit.agents.stack')); each part the agent does not name yet comes from the operator's new-agent defaults
 * (setting `agents.defaults`), else from Telenow's own recommendation for this account (`getAgentDefaults`). A part
 * nobody names fails the write with that reason (no point trying again until the operator picks one). The agent's
 * id is the Idempotency-Key, so a retried create cannot make two agents.
 */
final class CreateAtTelenow extends AgentWrite
{
    public function send(OutboxEntry $entry): void
    {
        $agent = $this->agent($entry);
        $key = $this->customerKey($agent);
        $body = $entry->body + $this->stack($key, $entry->body);
        $answer = $this->telenow->createAgent($key, $body, $entry->idempotency_key);
        $this->synced($agent, $this->manifest->object(self::RESOURCE, 'create', $answer));
    }

    /** @return array<string, string> the stack's parts the body lacks, by write name */
    private function stack(string $key, array $body): array
    {
        $stack = [];
        $operator = (array) $this->settings->get('agents.defaults', []);
        $recommended = null;
        $unnamed = [];
        foreach (config('kit.agents.stack') as $column) {
            $field = $this->manifest->writeName(self::RESOURCE, $column);
            if (isset($body[$field])) {
                continue;
            }
            $value = $operator[$column] ?? null;
            if (! is_string($value) || $value === '') {
                $recommended ??= $this->telenow->agentDefaults($key);
                $value = $recommended[$field] ?? null;
            }
            if (is_string($value) && $value !== '') {
                $stack[$field] = $value;
            } else {
                $unnamed[] = $field;
            }
        }
        if ($unnamed !== []) {
            throw new TelenowError(400, __('agents.no_default', ['what' => implode(', ', $unnamed)]));
        }

        return $stack;
    }
}
