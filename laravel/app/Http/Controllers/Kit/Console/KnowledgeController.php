<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Knowledge\AgentKnowledge;
use App\Kit\Knowledge\Knowledge;
use App\Kit\Knowledge\KnowledgeRefused;
use App\Kit\Knowledge\KnowledgeTables;
use App\Models\Agent;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Console → Knowledge (contract/kit-api.yaml listKnowledgeBases, createKnowledgeBase, getKnowledgeBase,
 * deleteKnowledgeBase, addKnowledgeText, uploadKnowledgeFile, addKnowledgePage, retryKnowledgeDocument,
 * deleteKnowledgeDocument, setAgentKnowledge, getKnowledgeTable, setKnowledgeColumnTypes): the account's knowledge bases, read from the
 * kit's DB, each with how many of its documents are ready, still being read and failed, and the agents that answer from it; a base with its
 * documents (each in the kit's words: saving, being read, ready, or failed and why, and whether it is a table); making and deleting them;
 * which bases an agent answers from; a table document's columns and rows, and how its columns are read (KnowledgeTables). Every member may.
 * Never Telenow's ids, its status words or its error text.
 */
final class KnowledgeController
{
    public function __construct(private readonly Knowledge $knowledge, private readonly AgentKnowledge $agentKnowledge, private readonly KnowledgeTables $tables) {}

    public function index(Request $request): JsonResponse
    {
        $bases = KnowledgeBase::query()->with(['agents', 'documents' => fn ($q) => $q->whereNull('deleted_at')])
            ->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json([
            'knowledgeBases' => $bases->map($this->presentBase(...))->all(),
            'limits' => ['fileMaxBytes' => Knowledge::fileMaxBytes(), 'fileTypes' => config('kit.knowledge.file_types'),
                'textMaxLength' => config('kit.knowledge.text_max_length')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json($this->presentBase($this->knowledge->createBase($this->membership($request)->workspace, $request->all())->load('agents', 'documents')), 201));
    }

    public function show(Request $request, string $id): JsonResponse|Response
    {
        return $this->withBase($request, $id, fn (KnowledgeBase $b) => response()->json($this->presentBase($b) + [
            'documents' => $b->documents->sortByDesc('created_at')->values()->map($this->presentDocument(...))->all(),
        ]));
    }

    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        return $this->withBase($request, $id, function (KnowledgeBase $b) {
            $this->knowledge->deleteBase($b);

            return response()->noContent();
        });
    }

    public function addText(Request $request, string $id): JsonResponse|Response
    {
        return $this->withBase($request, $id, fn (KnowledgeBase $b) => response()->json($this->presentDocument($this->knowledge->addText($b, $request->all())), 201));
    }

    public function upload(Request $request, string $id): JsonResponse|Response
    {
        return $this->withBase($request, $id, fn (KnowledgeBase $b) => response()->json($this->presentDocument($this->knowledge->addFile($b, $request->all())), 201));
    }

    public function addPage(Request $request, string $id): JsonResponse|Response
    {
        return $this->withBase($request, $id, fn (KnowledgeBase $b) => response()->json($this->presentDocument($this->knowledge->addPage($b, $request->all())), 201));
    }

    public function retry(Request $request, string $id, string $documentId): JsonResponse|Response
    {
        return $this->withDocument($request, $id, $documentId, fn (KnowledgeDocument $d) => response()->json($this->presentDocument($this->knowledge->retry($d))));
    }

    public function destroyDocument(Request $request, string $id, string $documentId): JsonResponse|Response
    {
        return $this->withDocument($request, $id, $documentId, function (KnowledgeDocument $d) {
            $this->knowledge->deleteDocument($d);

            return response()->noContent();
        });
    }

    /** A table document's columns and a page of its rows (`offset`, `limit`), read live. */
    public function table(Request $request, string $id, string $documentId): JsonResponse|Response
    {
        return $this->withDocument($request, $id, $documentId, fn (KnowledgeDocument $d) => response()->json($this->tables->read($d, $request->query())));
    }

    /** Change how some of a table's columns are read; answers its columns as they are now. */
    public function setTableTypes(Request $request, string $id, string $documentId): JsonResponse|Response
    {
        return $this->withDocument($request, $id, $documentId, fn (KnowledgeDocument $d) => response()->json(['columns' => $this->tables->setTypes($d, $request->all())]));
    }

    /** Which bases an agent answers from, all at once; answers the agent's bases. */
    public function setAgent(Request $request, string $agentId): JsonResponse
    {
        $agent = Str::isUuid($agentId) ? Agent::query()->with('workspace')->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')->find($agentId) : null;
        if ($agent === null) {
            return response()->json(['message' => __('agents.not_found')], 404);
        }
        $agent = $this->agentKnowledge->set($agent, $request->all());

        return response()->json(['knowledgeBases' => self::agentBases($agent), 'syncState' => $agent->sync_state, 'syncError' => $agent->sync_error]);
    }

    /** @return list<array{id: string, name: string}> the bases an agent answers from */
    public static function agentBases(Agent $agent): array
    {
        return $agent->knowledgeBases()->whereNull('knowledge_bases.deleted_at')->orderBy('name')->get()
            ->map(fn (KnowledgeBase $b) => ['id' => $b->id, 'name' => $b->name])->all();
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function withBase(Request $request, string $id, \Closure $act): JsonResponse|Response
    {
        $base = Str::isUuid($id) ? KnowledgeBase::query()->with(['workspace', 'agents', 'documents' => fn ($q) => $q->whereNull('deleted_at')])
            ->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')->find($id) : null;
        if ($base === null) {
            return response()->json(['message' => __('knowledge.not_found')], 404);
        }

        return $this->refusals(fn () => $act($base));
    }

    private function withDocument(Request $request, string $id, string $documentId, \Closure $act): JsonResponse|Response
    {
        return $this->withBase($request, $id, function (KnowledgeBase $base) use ($documentId, $act) {
            $doc = Str::isUuid($documentId) ? $base->documents->firstWhere('id', $documentId) : null;

            return $doc === null ? response()->json(['message' => __('knowledge.document_not_found')], 404) : $act($doc->setRelation('base', $base));
        });
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (KnowledgeRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function presentBase(KnowledgeBase $b): array
    {
        $counts = ['total' => 0, 'ready' => 0, 'working' => 0, 'failed' => 0];
        foreach ($b->documents as $d) {
            $counts['total']++;
            $state = self::state($d)['state'];
            $counts[$state === 'saving' ? 'working' : $state]++;
        }

        return [
            'id' => $b->id,
            'name' => $b->name,
            'description' => $b->description,
            'documentCounts' => $counts,
            'agents' => $b->agents->sortBy('name')->values()->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name])->all(),
            'syncState' => $b->sync_state,
            'syncError' => $b->sync_error,
            'createdAt' => $b->created_at->toIso8601String(),
        ];
    }

    private function presentDocument(KnowledgeDocument $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'kind' => match ($d->source_type) { 'upload' => 'file', 'url' => 'page', default => 'text' },
            'source' => $d->source_uri,
            'chars' => $d->char_count,
            'createdAt' => $d->created_at->toIso8601String(),
            'table' => KnowledgeTables::isTable($d),
        ] + self::state($d);
    }

    /** @return array{state: string, problem: ?string} a document in the kit's words */
    private static function state(KnowledgeDocument $d): array
    {
        $statuses = config('kit.knowledge.statuses');

        return match (true) {
            $d->sync_state === 'failed' => ['state' => 'failed', 'problem' => $d->sync_error],
            $d->sync_state === 'pending' && $d->telenow_id === null => ['state' => 'saving', 'problem' => null],
            in_array($d->status, $statuses['ready'], true) => ['state' => 'ready', 'problem' => null],
            in_array($d->status, $statuses['failed'], true) => ['state' => 'failed', 'problem' => __('knowledge.not_read')],
            default => ['state' => 'working', 'problem' => null],
        };
    }
}
