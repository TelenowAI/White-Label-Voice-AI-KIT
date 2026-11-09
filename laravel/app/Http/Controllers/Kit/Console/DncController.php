<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Dnc\DncExport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Kit\Dnc\Dnc;
use App\Kit\Dnc\DncRefused;
use App\Models\DncEntry;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Console → Do not call (contract/kit-api.yaml listDncEntries, addDncEntry, removeDncEntry, importDncEntries): the
 * current account's list from the kit's own copy, newest first, searchable by number or reason; adding a number,
 * removing one, importing a CSV. Every member may change it. No Telenow id, nor who at Telenow added it.
 */
final class DncController
{
    public function __construct(private readonly Dnc $dnc) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = config('kit.dnc.per_page');
        $page = max(1, (int) $request->query('page', 1));
        $query = DncEntry::query()->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderByDesc('id');
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->whereLike('phone_number', $like)->orWhereLike('reason', $like));
        }

        return response()->json([
            'entries' => $query->clone()->forPage($page, $perPage)->get()->map($this->present(...))->all(),
            'total' => $query->count(), 'page' => $page, 'perPage' => $perPage,
        ]);
    }

    /** The list as a file (contract exportDnc). */
    public function export(Request $request, DncExport $export): StreamedResponse
    {
        return $export->download($this->membership($request)->workspace);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json($this->present($this->dnc->add($this->membership($request)->workspace, $request->all())), 201));
    }

    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        $row = Str::isUuid($id) ? DncEntry::query()->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')->find($id) : null;
        if ($row === null) {
            return response()->json(['message' => __('dnc.not_found')], 404);
        }
        $this->dnc->remove($row);

        return response()->noContent();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['csv' => ['required', 'string']], [], ['csv' => __('dnc.fields.csv')]);

        return $this->refusals(fn () => response()->json($this->dnc->import($this->membership($request)->workspace, $request->input('csv'))));
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function refusals(\Closure $act): JsonResponse
    {
        try {
            return $act();
        } catch (DncRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function present(DncEntry $e): array
    {
        return [
            'id' => $e->id,
            'phoneNumber' => $e->phone_number,
            'reason' => $e->reason,
            'source' => $e->source,
            'callId' => $e->call_id,
            'syncState' => $e->sync_state,
            'syncError' => $e->sync_error,
            'createdAt' => $e->created_at->toIso8601String(),
        ];
    }
}
