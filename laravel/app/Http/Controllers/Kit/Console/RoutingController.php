<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Routing\RoutingConnection;
use App\Kit\Routing\RoutingMatch;
use App\Kit\Routing\RoutingRefused;
use App\Kit\Routing\RoutingRules;
use App\Models\PhoneNumber;
use App\Models\RoutingRule;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Console → Call routing (contract/kit-api.yaml getRouting, createRoutingRule, updateRoutingRule, deleteRoutingRule, orderRoutingRules, tryRouting,
 * checkRouting): the account's rules in order, its numbers and which are routed, and whether the voice platform asks this kit (RoutingConnection);
 * trying a pretend call against the rules (which rule, what happens), and the platform's own test of a routed number. Every member sees them; the
 * account's managers change them and run the platform's test. Never Telenow's ids, its words or the secret.
 */
final class RoutingController
{
    public function __construct(private readonly RoutingRules $rules, private readonly RoutingConnection $connection) {}

    public function index(Request $request): JsonResponse
    {
        $m = $this->membership($request);
        $c = config('kit.routing');

        return response()->json([
            'rules' => array_map($this->present(...), $this->rules->list($m->workspace)),
            'numbers' => $this->numbers($m)->with('agent')->orderBy('created_at')->orderBy('id')->get()->map(fn (PhoneNumber $n) => [
                'id' => $n->id, 'number' => NumbersController::plus($n->e164), 'agent' => $n->agent ? ['id' => $n->agent->id, 'name' => $n->agent->name] : null,
                'routed' => (bool) $n->routed, 'syncState' => $n->sync_state, 'syncError' => $n->sync_error,
            ])->all(),
            'connection' => $this->connection->status($m->workspace),
            'canManage' => $this->manages($m),
            'limits' => ['rulesMax' => $c['rules_max'], 'numbersPerRule' => $c['numbers_per_rule'], 'nameMaxLength' => $c['name_max_length'],
                'greetingMaxLength' => $c['greeting_max_length'], 'languageMaxLength' => $c['language_max_length'], 'maxMinutes' => $c['max_minutes']],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->managing($request, fn (WorkspaceMember $m) => response()->json($this->present($this->rules->create($m->workspace, $request->all())), 201));
    }

    public function update(Request $request, string $id): JsonResponse|Response
    {
        return $this->managing($request, fn (WorkspaceMember $m) => $this->withRule($m, $id, fn (RoutingRule $r) => response()->json($this->present($this->rules->update($r, $request->all())))));
    }

    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        return $this->managing($request, fn (WorkspaceMember $m) => $this->withRule($m, $id, function (RoutingRule $r) {
            $this->rules->delete($r);

            return response()->noContent();
        }));
    }

    public function order(Request $request): JsonResponse
    {
        return $this->managing($request, function (WorkspaceMember $m) use ($request) {
            $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['string']]);
            $this->rules->order($m->workspace, $request->input('ids'));

            return response()->json(['rules' => array_map($this->present(...), $this->rules->list($m->workspace))]);
        });
    }

    /** A pretend call: which rule takes it (none: the number's own agent) and what happens, at a time given or now. Asks no one. */
    public function try(Request $request): JsonResponse
    {
        $data = $request->validate([
            'numberId' => ['required', 'string'],
            'callerNumber' => ['nullable', 'string', 'max:32'],
            'forwardedFrom' => ['nullable', 'string', 'max:64'],
            'at' => ['nullable', 'date'],
        ]);
        $m = $this->membership($request);
        $number = Str::isUuid($data['numberId']) ? $this->numbers($m)->with('agent')->find($data['numberId']) : null;
        if ($number === null) {
            return response()->json(['message' => __('routing.unknown_number'), 'errors' => ['numberId' => [__('routing.unknown_number')]]], 422);
        }
        $at = isset($data['at']) ? CarbonImmutable::parse($data['at']) : CarbonImmutable::now();
        $rule = RoutingMatch::first($this->rules->list($m->workspace), $number->id, $data['callerNumber'] ?? null, $data['forwardedFrom'] ?? null, $at);
        $agent = $rule !== null && $rule->action === 'answer' && $this->alive($rule) ? $rule->agent : $number->agent;

        return response()->json([
            'rule' => $rule ? ['id' => $rule->id, 'name' => $rule->name] : null,
            'outcome' => $rule === null ? 'own_agent' : $rule->action,
            'agent' => $rule?->action === 'refuse' || $agent === null ? null : ['id' => $agent->id, 'name' => $agent->name],
            'language' => $rule?->language,
            'greeting' => $rule?->greeting,
            'maxMinutes' => $rule?->max_minutes,
            'routed' => (bool) $number->routed,
        ]);
    }

    /** The voice platform's own test of a routed number, through this kit's endpoint. */
    public function check(Request $request): JsonResponse
    {
        return $this->managing($request, function (WorkspaceMember $m) use ($request) {
            $request->validate(['numberId' => ['required', 'string']]);
            $id = (string) $request->input('numberId');
            $number = Str::isUuid($id) ? $this->numbers($m)->find($id) : null;
            if ($number === null) {
                return response()->json(['message' => __('routing.unknown_number'), 'errors' => ['numberId' => [__('routing.unknown_number')]]], 422);
            }
            if (! $number->routed) {
                return response()->json(['message' => __('routing.number_not_routed')], 409);
            }

            return response()->json($this->connection->check($m->workspace, $number));
        });
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function manages(WorkspaceMember $m): bool
    {
        return in_array($m->role, config('kit.team.managers'), true);
    }

    private function managing(Request $request, \Closure $act): JsonResponse|Response
    {
        $m = $this->membership($request);
        if (! $this->manages($m)) {
            return response()->json(['message' => __('routing.managers_only')], 403);
        }
        try {
            return $act($m);
        } catch (RoutingRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function withRule(WorkspaceMember $m, string $id, \Closure $act): JsonResponse|Response
    {
        $rule = Str::isUuid($id) ? RoutingRule::query()->where('workspace_id', $m->workspace_id)->find($id) : null;
        if ($rule === null) {
            return response()->json(['message' => __('routing.not_found')], 404);
        }

        return $act($rule);
    }

    /** The account's numbers that can take calls: bought and not released. */
    private function numbers(WorkspaceMember $m): \Illuminate\Database\Eloquent\Builder
    {
        return PhoneNumber::query()->where('workspace_id', $m->workspace_id)->whereNull('released_at')->whereNotNull('telenow_id');
    }

    private function alive(RoutingRule $rule): bool
    {
        return $rule->agent !== null && $rule->agent->deleted_at === null;
    }

    private function present(RoutingRule $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'enabled' => (bool) $r->enabled,
            'numberIds' => array_values((array) $r->numbers),
            'forwardedFrom' => array_values((array) $r->forwarded_from),
            'callerStartsWith' => array_values((array) $r->caller_starts),
            'days' => array_values(array_map('intval', (array) $r->days)),
            'from' => $r->from_time,
            'until' => $r->until_time,
            'timezone' => $r->timezone,
            'action' => $r->action,
            'agent' => $this->alive($r) ? ['id' => $r->agent->id, 'name' => $r->agent->name] : null,
            'agentGone' => $r->agent_id !== null && ! $this->alive($r),
            'language' => $r->language,
            'greeting' => $r->greeting,
            'maxMinutes' => $r->max_minutes,
        ];
    }
}
