<?php

namespace App\Kit\Knowledge;

use App\Kit\Agents\Telenow\AgentWrite;
use App\Kit\Outbox\Outbox;
use App\Models\Agent;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Which knowledge bases an agent answers from (console → an agent → Knowledge): the whole set at once, only the
 * account's own bases, kept in the kit and sent to Telenow at once (Telenow/SetAgentKnowledgeAtTelenow sends the set as
 * it stands then).
 */
final class AgentKnowledge
{
    public function __construct(private readonly Outbox $outbox) {}

    public function set(Agent $agent, array $input): Agent
    {
        $values = Validator::make($input, ['knowledgeBaseIds' => ['present', 'array'], 'knowledgeBaseIds.*' => ['string']],
            [], ['knowledgeBaseIds' => __('knowledge.fields.knowledgeBaseIds')])->validate();
        $ids = array_values(array_unique($values['knowledgeBaseIds']));
        $bases = KnowledgeBase::query()->where('workspace_id', $agent->workspace_id)->whereNull('deleted_at')->whereIn('id', $ids)->pluck('id');
        if ($bases->count() !== count($ids)) {
            throw ValidationException::withMessages(['knowledgeBaseIds' => [__('knowledge.unknown_base')]]);
        }
        DB::transaction(function () use ($agent, $bases) {
            $agent->knowledgeBases()->sync($bases->all());
            $agent->forceFill(['sync_state' => 'pending'])->save();
            $this->outbox->enqueue(AgentWrite::RESOURCE, $agent->id, 'knowledge', null);
        });
        $this->outbox->runFor(AgentWrite::RESOURCE, $agent->id);

        return $agent->fresh();
    }
}
