<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Agents\AgentRefused;
use App\Kit\Agents\StarterAgents;
use App\Models\StarterAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin → Starter agents (contract/kit-api.yaml listStarterAgents, previewStarterAgent, publishStarterAgent, updateStarterAgent): the starters the
 * operator offers every account, and publishing one from an agent in an account the signed-in staff member owns (App\Kit\Agents\StarterAgents).
 * Needs the `starters` ability (config('kit.admin.abilities')).
 */
final class StartersController
{
    public function __construct(private readonly StarterAgents $starters) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'starters' => StarterAgent::query()->with('publisher')->orderByDesc('published_at')->orderByDesc('id')->get()->map(self::present(...))->all(),
            'sources' => $this->starters->sources($request->user()),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json($this->starters->preview($request->user(), $request->query())));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json(self::present($this->starters->publish($request->user(), $request->all())->load('publisher')), 201));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $starter = Str::isUuid($id) ? StarterAgent::query()->find($id) : null;
        if ($starter === null) {
            return response()->json(['message' => __('starters.not_found')], 404);
        }

        return response()->json(self::present($this->starters->change($request->user(), $starter, $request->all())->load('publisher')));
    }

    private function refusals(\Closure $act): JsonResponse
    {
        try {
            return $act();
        } catch (AgentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private static function present(StarterAgent $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'offered' => $s->withdrawn_at === null,
            'steps' => count($s->flow['steps'] ?? []),
            'from' => ['agent' => $s->source_agent_name, 'account' => $s->source_account_name],
            'publishedBy' => $s->publisher?->name, 'publishedAt' => $s->published_at->toIso8601String(),
        ];
    }
}
