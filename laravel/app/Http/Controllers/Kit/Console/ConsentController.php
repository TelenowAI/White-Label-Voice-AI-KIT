<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Consent\ConsentLedger;
use App\Kit\Consent\ConsentRefused;
use App\Kit\Consent\ConsentRule;
use App\Kit\Telenow\TelenowError;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Console → Consent (contract/kit-api.yaml listConsent, recordConsent, importConsent, withdrawConsent, setConsentRule): the current account's records from the kit's own copy, newest first, searchable by number; recording one, a file of them, withdrawing a
 * number's; and whether its calls need consent, with how many waiting calls that touches. Every member may record and withdraw, as on the do-not-call list; only the account's managers may change the rule. No Telenow id, nor its own record of who sent what.
 */
final class ConsentController
{
    public function __construct(private readonly ConsentLedger $ledger, private readonly ConsentRule $rule) {}

    public function index(Request $request): JsonResponse
    {
        $membership = $this->membership($request);
        $found = $this->ledger->page($membership->workspace, trim((string) $request->query('search', '')), max(1, (int) $request->query('page', 1)), config('kit.consent.per_page'));
        $c = config('kit.consent');

        return response()->json([
            'records' => $this->ledger->present($found->items(), $membership->workspace_id),
            'total' => $found->total(), 'page' => $found->currentPage(), 'perPage' => $found->perPage(),
            'rule' => $this->rule->state($membership->workspace) + ['canChange' => $this->manages($membership)],
            'limits' => ['types' => $c['types'], 'writtenType' => $c['written_type'], 'scopes' => $c['scopes'], 'defaultScope' => $c['default_scope'], 'sources' => $c['sources'], 'phoneColumns' => config('kit.campaigns.phone_columns'),
                'capturedColumn' => $c['captured_column'], 'expiresColumn' => $c['expires_column'], 'importMaxBytes' => $c['import_max_bytes'], 'importMaxRows' => $c['import_max_rows']],
        ]);
    }

    public function store(Request $request): JsonResponse|Response
    {
        return $this->refusals(function () use ($request) {
            $membership = $this->membership($request);
            $row = $this->ledger->record($membership->workspace, $request->all(), $request->user(), $request->ip(), $request->userAgent());

            return response()->json($this->ledger->present([$row], $membership->workspace_id)[0], 201);
        });
    }

    public function import(Request $request): JsonResponse|Response
    {
        return $this->refusals(fn () => response()->json($this->ledger->import($this->membership($request)->workspace, $request->all(), $request->user(), $request->ip(), $request->userAgent())));
    }

    public function withdraw(Request $request): JsonResponse|Response
    {
        return $this->refusals(function () use ($request) {
            $this->ledger->withdraw($this->membership($request)->workspace, $request->all(), $request->user());

            return response()->noContent();
        });
    }

    public function setRule(Request $request): JsonResponse|Response
    {
        $values = $request->validate(['required' => ['required', 'boolean']], [], ['required' => __('consent.fields.required')]);
        $membership = $this->membership($request);
        if (! $this->manages($membership)) {
            return response()->json(['message' => __('consent.only_managers')], 403);
        }

        return $this->refusals(function () use ($membership, $values) {
            $this->rule->setRequired($membership->workspace, (bool) $values['required']);

            return response()->json($this->rule->state($membership->workspace->fresh()) + ['canChange' => true]);
        });
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function manages(WorkspaceMember $membership): bool
    {
        return in_array($membership->role, config('kit.team.managers'), true);
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (ConsentRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
