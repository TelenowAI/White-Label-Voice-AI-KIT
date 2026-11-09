<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Campaigns\CampaignPeople;
use App\Kit\Campaigns\CampaignProgress;
use App\Kit\Campaigns\CampaignRefused;
use App\Kit\Campaigns\CampaignRetries;
use App\Kit\Campaigns\CampaignSources;
use App\Kit\Campaigns\Campaigns;
use App\Models\Campaign;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Console → Campaigns (contract/kit-api.yaml listCampaigns, createCampaign, getCampaign, updateCampaign, deleteCampaign, addCampaignTargets,
 * listCampaignTargets, exportCampaignTargets, retryCampaign, startCampaign, pauseCampaign, cancelCampaign, getCampaignReach, getCampaignVariables, getCampaignProgress): the account's
 * campaigns, read from the kit's DB — each with its agent, settings, where it stands (saving, a draft, running, waiting
 * for its calling window, paused, finished) and how many of its people have been called — its call list, and what can be
 * done with it. Every member may. Never Telenow's ids or its own messages.
 */
final class CampaignsController
{
    public function __construct(private readonly Campaigns $campaigns, private readonly CampaignPeople $people, private readonly CampaignRetries $retries, private readonly CampaignProgress $progress, private readonly CampaignSources $sources) {}

    public function index(Request $request): JsonResponse
    {
        $c = config('kit.campaigns');

        return response()->json([
            'campaigns' => Campaign::query()->with(['agent', 'callerId'])
                ->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')
                ->orderByDesc('created_at')->orderByDesc('id')->get()->map($this->present(...))->all(),
            'limits' => ['nameMaxLength' => $c['name_max_length'], 'concurrency' => $c['concurrency'], 'maxAttempts' => $c['max_attempts'], 'retryGapMinutes' => $c['retry_gap_minutes'],
                'machineDetection' => $c['machine_detection'], 'phoneColumns' => $c['phone_columns'],
                'importMaxBytes' => $c['import_max_bytes'], 'importMaxRows' => $c['import_max_rows'],
                'linksAllowed' => $this->sources->allowed(), 'linkEveryMinutes' => $c['link_every_minutes']],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->refusals(fn () => response()->json($this->present($this->campaigns->create($this->membership($request)->workspace, $request->all())->load('agent', 'callerId')), 201));
    }

    public function show(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json($this->present($c)));
    }

    public function update(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json($this->present($this->campaigns->update($c, $request->all())->load('agent', 'callerId'))));
    }

    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (Campaign $c) {
            $this->campaigns->delete($c);

            return response()->noContent();
        });
    }

    public function addTargets(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json($this->campaigns->addTargets($c, $request->all())));
    }

    /** People from a link: set it and read it now (CampaignSources); answers what it added and the campaign. */
    public function setSource(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (Campaign $c) use ($request) {
            $added = $this->sources->set($c, $request->all());

            return response()->json(['added' => $added, 'campaign' => $this->present($c->fresh()->load('agent', 'callerId'))]);
        });
    }

    /** Stop following the campaign's link; the people it added stay. */
    public function removeSource(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (Campaign $c) {
            $this->sources->remove($c);

            return response()->noContent();
        });
    }

    public function targets(Request $request, string $id): JsonResponse|Response
    {
        $perPage = config('kit.campaigns.per_page');
        $page = max(1, (int) $request->query('page', 1));
        $show = $this->which($request);

        return $this->with($request, $id, function (Campaign $c) use ($perPage, $page, $show) {
            $found = $this->people->page($c, $show, $page, $perPage);

            return response()->json([
                'targets' => array_map($this->people->row(...), $found->items()),
                'total' => $found->total(), 'page' => $page, 'perPage' => $perPage, 'counts' => $this->people->counts($c),
            ]);
        });
    }

    public function export(Request $request, string $id): JsonResponse|Response|StreamedResponse
    {
        $show = $this->which($request);
        $campaign = $this->find($request, $id);

        return $campaign === null ? $this->notFound() : $this->people->export($campaign, $show);
    }

    public function retry(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, function (Campaign $c) use ($request) {
            $done = $this->retries->retry($c, $request->all());

            return response()->json(['retried' => $done['retried'], 'campaign' => $this->present($done['campaign']->load('agent', 'callerId'))]);
        });
    }

    public function progress(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json($this->progress->of($c)));
    }

    public function start(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json($this->present($this->campaigns->start($c)->load('agent', 'callerId'))));
    }

    public function pause(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json($this->present($this->campaigns->pause($c)->load('agent', 'callerId'))));
    }

    public function cancel(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json($this->present($this->campaigns->cancel($c)->load('agent', 'callerId'))));
    }

    public function reach(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json($this->campaigns->reach($c)));
    }

    public function variables(Request $request, string $id): JsonResponse|Response
    {
        return $this->with($request, $id, fn (Campaign $c) => response()->json(['variables' => $this->campaigns->variables($c)]));
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function with(Request $request, string $id, \Closure $act): JsonResponse|Response
    {
        $campaign = $this->find($request, $id);
        if ($campaign === null) {
            return $this->notFound();
        }

        return $this->refusals(fn () => $act($campaign));
    }

    private function find(Request $request, string $id): ?Campaign
    {
        return Str::isUuid($id) ? Campaign::query()->with(['workspace', 'agent', 'callerId'])
            ->where('workspace_id', $this->membership($request)->workspace_id)->whereNull('deleted_at')->find($id) : null;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('campaigns.not_found')], 404);
    }

    /** Which of its people a customer asked for: all of them when none is named. */
    private function which(Request $request): ?string
    {
        return Validator::make($request->query(), ['show' => ['nullable', Rule::in(config('kit.campaigns.people_filters'))]], [], ['show' => __('campaigns.fields.show')])->validate()['show'] ?? null;
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (CampaignRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function present(Campaign $c): array
    {
        $block = is_array($c->dial_block) ? $c->dial_block : null;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'agent' => $c->agent ? ['id' => $c->agent->id, 'name' => $c->agent->name] : null,
            'callerId' => $c->callerId ? ['id' => $c->callerId->id, 'number' => NumbersController::plus($c->callerId->e164)] : null,
            'settings' => [
                'concurrency' => $c->concurrency, 'windowStart' => $c->start_time_local, 'windowEnd' => $c->end_time_local, 'timezone' => $c->timezone,
                'machineDetection' => $c->machine_detection, 'maxAttempts' => $c->max_attempts, 'retryOnNoAnswer' => $c->retry_on_no_answer,
                'retryGapMinutes' => (int) ceil($c->retry_backoff_secs / 60),
            ],
            'status' => match (true) {
                $c->sync_state === 'failed' && $c->telenow_id === null => 'failed',
                $c->telenow_id === null => 'saving',
                default => $c->status ?? 'saving',
            },
            // Why a running campaign is not calling right now, in the console's words (never Telenow's message).
            'waiting' => $block === null || ! in_array($c->status, config('kit.campaigns.active_statuses'), true) ? null
                : ['reason' => ($block['kind'] ?? null) === 'window' ? 'window' : 'other', 'opensAt' => $block['opensAt'] ?? null],
            'counts' => ['people' => $c->total_targets, 'answered' => $c->completed_targets, 'notReached' => $c->failed_targets],
            'pushed' => array_merge(['added' => 0, 'duplicates' => 0, 'suppressed' => 0, 'skipped' => 0], (array) ($c->push_summary ?? [])),
            'syncState' => $c->sync_state,
            'syncError' => $c->sync_error,
            // People from a link (CampaignSources): the link, when it was last read, why not (the customer's words), and how many it added.
            'source' => $c->source_url === null ? null : ['url' => $c->source_url, 'checkedAt' => $c->source_checked_at?->toIso8601String(),
                'problem' => $c->source_problem, 'added' => (int) $c->source_added],
            'startedAt' => $c->started_at?->toIso8601String(),
            'completedAt' => $c->completed_at?->toIso8601String(),
            'createdAt' => $c->created_at->toIso8601String(),
        ];
    }
}
