<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Numbers\LostNumbers;
use App\Kit\Numbers\NumberRenewals;
use App\Kit\Numbers\Numbers;
use App\Kit\Numbers\NumbersRefused;
use App\Kit\Routing\RoutingRefused;
use App\Models\PhoneNumber;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Console → Numbers (contract/kit-api.yaml searchAvailableNumbers, listPhoneNumbers, buyPhoneNumber, setPhoneNumberAgent,
 * releasePhoneNumber, setNumberRouting, setNumberCallerId, setNumberUnanswered, listLostNumbers, getLostNumberBack,
 * getNumberRenewals): numbers for sale with what they would cost on the account's plan; the account's numbers (a released
 * one is listed until Telenow has it back: `releasing`); buying one, choosing the agent that answers it, making it that
 * agent's caller id, what a call does while no agent answers it, releasing it, getting back one the platform gave up,
 * whether the balance covers the next rent — every member may; whether its calls follow the account's routing rules — its
 * managers. Never the carrier, Telenow's ids, its prices or its words.
 */
final class NumbersController
{
    public function __construct(private readonly Numbers $numbers, private readonly LostNumbers $lost, private readonly NumberRenewals $renewals) {}

    public function available(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json(['numbers' => array_map(fn (array $o) => [
            'number' => self::plus($o['listing']['e164'] ?? ''),
            'country' => $o['listing']['country'] ?? null,
            'region' => $o['listing']['region'] ?? null,
            'type' => $o['listing']['number_type'] ?? null,
            'voice' => (bool) ($o['listing']['capabilities']['voice'] ?? false),
            'sms' => (bool) ($o['listing']['capabilities']['sms'] ?? false),
            'setupPrice' => $o['setup'],
            'monthlyPrice' => $o['monthly'],
        ], $this->numbers->search($this->membership($request)->workspace, $request->query()))]));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['numbers' => $this->held($request)->with('agent')
            ->orderByDesc('created_at')->orderByDesc('id')->get()->map($this->present(...))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json($this->present($this->numbers->buy($this->membership($request)->workspace, $request->all())->load('agent')), 201));
    }

    public function agent(Request $request, string $id): JsonResponse
    {
        $request->validate(['agentId' => ['present', 'nullable', 'string']]);

        return $this->with($request, $id, fn (PhoneNumber $n) => response()->json($this->present($this->numbers->assign($n, $request->input('agentId'))->load('agent'))));
    }

    public function routing(Request $request, string $id): JsonResponse|Response
    {
        if (! in_array($this->membership($request)->role, config('kit.team.managers'), true)) {
            return response()->json(['message' => __('routing.managers_only')], 403);
        }
        $request->validate(['routed' => ['required', 'boolean']]);

        return $this->with($request, $id, fn (PhoneNumber $n) => response()->json($this->present($this->numbers->route($n, $request->boolean('routed'))->load('agent'))));
    }

    public function callerId(Request $request, string $id): JsonResponse|Response
    {
        $request->validate(['agentCallerId' => ['required', 'boolean']]);

        return $this->with($request, $id, fn (PhoneNumber $n) => response()->json($this->present($this->numbers->callerId($n, $request->boolean('agentCallerId'))->load('agent'))));
    }

    public function unanswered(Request $request, string $id): JsonResponse|Response
    {
        $request->validate(['unanswered' => ['required', 'string', Rule::in(array_keys(config('kit.numbers.unanswered')))]], ['unanswered.in' => __('numbers.unknown_unanswered')]);

        return $this->with($request, $id, fn (PhoneNumber $n) => response()->json($this->present($this->numbers->unanswered($n, $request->input('unanswered'))->load('agent'))));
    }

    /** Numbers the platform gave up (rent not paid) that can still be got back, and what that costs on the account's plan. */
    public function lost(Request $request): JsonResponse|Response
    {
        return $this->refusals(fn () => response()->json(['numbers' => array_map(fn (array $l) => [
            'id' => $l['number']->id,
            'number' => self::plus($l['number']->e164),
            'releasedAt' => $l['number']->released_at?->toIso8601String(),
            'until' => $l['until'] !== null ? Carbon::parse($l['until'])->toIso8601String() : null,
            'askedAt' => $l['askedAt'] !== null ? Carbon::parse($l['askedAt'])->toIso8601String() : null,
            'reason' => $l['unpaid'] ? 'unpaid' : 'other',
            'agent' => $l['agent'] ? ['id' => $l['agent']->id, 'name' => $l['agent']->name] : null,
            'wasCallerId' => $l['wasCallerId'],
            'setupPrice' => $l['setup'],
            'monthlyPrice' => $l['monthly'],
        ], $this->lost->list($this->membership($request)->workspace))]));
    }

    public function getBack(Request $request, string $id): JsonResponse|Response
    {
        $workspace = $this->membership($request)->workspace;
        $number = Str::isUuid($id) ? PhoneNumber::query()->where('workspace_id', $workspace->id)->whereNotNull('released_at')->find($id) : null;
        if ($number === null) {
            return response()->json(['message' => __('numbers.not_found')], 404);
        }

        return $this->refusals(function () use ($workspace, $number) {
            $done = $this->lost->getBack($workspace, $number);

            return response()->json($done['outcome'] === 'restored'
                ? ['outcome' => 'restored', 'number' => $this->present($done['number']->load('agent'))]
                : ['outcome' => 'requested', 'until' => $done['until'] !== null ? Carbon::parse($done['until'])->toIso8601String() : null]);
        });
    }

    /** Whether the balance covers each number's next rent, in renewal order (the kit's own reckoning; App\Kit\Numbers\NumberRenewals). */
    public function renewals(Request $request): JsonResponse
    {
        $of = $this->renewals->of($this->membership($request)->workspace);

        return response()->json([
            'charging' => $of['inForce'],
            'balance' => $of['balance'],
            'soonDays' => $of['soonDays'],
            'numbers' => array_map(fn (array $r) => [
                'id' => $r['number']->id,
                'number' => self::plus($r['number']->e164),
                'renewsAt' => $r['number']->next_renewal_at->toIso8601String(),
                'rent' => $r['rent'],
                'status' => $r['status'],
                'shortBy' => $r['shortBy'],
            ], $of['numbers']),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (PhoneNumber $n) {
            $this->numbers->release($n);

            return response()->noContent();
        });
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function with(Request $request, string $id, \Closure $act): JsonResponse|Response
    {
        $number = Str::isUuid($id) ? $this->held($request)->find($id) : null;
        if ($number === null) {
            return response()->json(['message' => __('numbers.not_found')], 404);
        }

        return $this->refusals(fn () => $act($number));
    }

    /** The account's numbers: not released, or released and not yet given back. */
    private function held(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return PhoneNumber::query()->where('workspace_id', $this->membership($request)->workspace_id)
            ->where(fn ($q) => $q->whereNull('released_at')->orWhere('sync_state', '!=', 'synced'));
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (NumbersRefused|RoutingRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    /** A number as people read it: E.164 with its +, whatever form the carrier keeps it in. */
    public static function plus(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);

        return $digits === '' ? $number : '+'.$digits;
    }

    private function present(PhoneNumber $n): array
    {
        return [
            'id' => $n->id,
            'number' => self::plus($n->e164),
            'country' => $n->country,
            'region' => $n->region,
            'type' => $n->number_type,
            'agent' => $n->agent ? ['id' => $n->agent->id, 'name' => $n->agent->name] : null,
            'routed' => (bool) $n->routed,
            'agentCallerId' => (bool) $n->agent_caller_id,
            'unanswered' => Numbers::unansweredOf($n),
            'monthlyPrice' => $n->monthly_price !== null ? ['amount' => $n->monthly_price, 'currency' => $n->price_currency] : null,
            'nextRenewalAt' => $n->next_renewal_at?->toIso8601String(),
            'releasing' => $n->released_at !== null,
            'syncState' => $n->sync_state,
            'syncError' => $n->sync_error,
            'createdAt' => $n->created_at->toIso8601String(),
        ];
    }
}
