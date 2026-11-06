<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Setup\Field;
use App\Kit\Sms\Sms;
use App\Kit\Sms\SmsRefused;
use App\Kit\Sms\SmsSenders;
use App\Kit\Sms\SmsSetup;
use App\Models\OutgoingSms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin → SMS (contract/kit-api.yaml getSmsSettings, addSmsSender, updateSmsSender, deleteSmsSender, orderSmsSenders,
 * sendTestSms): the senders in the order they are tried, each drawn from its driver's own fields (a secret is never
 * answered); adding or changing one only after it has texted the number the staff member gives; a test through the
 * whole line; and the recent texts with how each went (never their words).
 *
 * Not here: sending (App\Kit\Sms\Sms).
 */
final class SmsController
{
    public function __construct(private readonly SmsSenders $senders, private readonly SmsSetup $setup, private readonly Sms $sms) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function addSender(Request $request): JsonResponse
    {
        return $this->refusals(function () use ($request) {
            $this->setup->add($request->all());

            return response()->json($this->payload(), 201);
        });
    }

    public function updateSender(Request $request, string $id): JsonResponse
    {
        return $this->refusals(function () use ($request, $id) {
            $this->setup->update($id, $request->all());

            return response()->json($this->payload());
        });
    }

    public function deleteSender(string $id): JsonResponse|Response
    {
        return $this->refusals(function () use ($id) {
            $this->setup->remove($id);

            return response()->noContent();
        });
    }

    public function order(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['present', 'array'], 'ids.*' => ['string']]);

        return $this->refusals(function () use ($request) {
            $this->setup->order($request->input('ids'));

            return response()->json($this->payload());
        });
    }

    /** One test text to the number given, through the senders in order, kept in the recent list. */
    public function test(Request $request): JsonResponse
    {
        if (! $this->sms->on()) {
            return response()->json(['message' => __('sms.no_sender')], 409);
        }
        $sms = $this->sms->send('test', $this->setup->testNumber($request->all()));

        return response()->json($this->present($sms));
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (SmsRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function payload(): array
    {
        $fields = fn (array $fs) => array_map(fn (Field $f) => $f->toArray(), $fs);

        return [
            'on' => $this->sms->on(),
            'maxSenders' => config('kit.sms.max_senders'),
            'drivers' => array_map(fn (string $id) => ['id' => $id, 'name' => __("sms.drivers.{$id}.name"),
                'fields' => $fields($this->senders->driver($id)->fields([]))], array_keys(config('kit.sms.drivers'))),
            'senders' => array_map(function (array $s) use ($fields) {
                $driver = $this->senders->driver($s['driver']);

                return ['id' => $s['id'], 'driver' => $s['driver'], 'driverName' => __("sms.drivers.{$s['driver']}.name"),
                    'label' => $driver->label($s['config']), 'fields' => $fields($driver->fields($s['config']))];
            }, $this->senders->all()),
            'recent' => OutgoingSms::query()->orderByDesc('created_at')->orderByDesc('id')->limit(config('kit.sms.recent'))->get()
                ->map($this->present(...))->all(),
        ];
    }

    private function present(OutgoingSms $s): array
    {
        return [
            'id' => $s->id, 'kind' => $s->kind, 'kindName' => __("sms.kind_names.{$s->kind}"), 'to' => $s->to_number,
            'status' => $s->status, 'attempts' => $s->attempts, 'lastError' => $s->last_error,
            'createdAt' => $s->created_at->toIso8601String(), 'sentAt' => $s->sent_at?->toIso8601String(),
        ];
    }
}
