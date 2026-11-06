<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Numbers\Numbers;
use App\Kit\Numbers\NumbersRefused;
use App\Models\PhoneNumber;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Console → Numbers (contract/kit-api.yaml searchAvailableNumbers, listPhoneNumbers, buyPhoneNumber, setPhoneNumberAgent,
 * releasePhoneNumber): numbers for sale with what they would cost on the account's plan; the account's numbers (a
 * released one is listed until Telenow has it back: `releasing`); buying one, choosing the agent that answers it,
 * releasing it. Every member may. Never the carrier, Telenow's ids or its prices.
 */
final class NumbersController
{
    public function __construct(private readonly Numbers $numbers) {}

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
        } catch (NumbersRefused $e) {
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
            'monthlyPrice' => $n->monthly_price !== null ? ['amount' => $n->monthly_price, 'currency' => $n->price_currency] : null,
            'nextRenewalAt' => $n->next_renewal_at?->toIso8601String(),
            'releasing' => $n->released_at !== null,
            'syncState' => $n->sync_state,
            'syncError' => $n->sync_error,
            'createdAt' => $n->created_at->toIso8601String(),
        ];
    }
}
