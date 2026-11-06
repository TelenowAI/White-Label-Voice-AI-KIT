<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Email\EmailRefused;
use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Email\SenderSetup;
use App\Kit\Setup\Field;
use App\Models\OutgoingEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin → Email (contract/kit-api.yaml getEmailSettings, saveEmailSite, addEmailSender, updateEmailSender,
 * deleteEmailSender, orderEmailSenders, sendTestEmail): the site's address and who emails come from; the senders in
 * the order they are tried, each drawn from its driver's own fields (a password is never answered); adding or changing
 * one only after it has sent a test to the signed-in staff member; a test through the whole line; and the recent
 * emails with how each went (never their bodies).
 *
 * Not here: the wizard's Email step (EmailStep), sending (App\Kit\Email\Emails).
 */
final class EmailController
{
    public function __construct(private readonly Senders $senders, private readonly SenderSetup $setup, private readonly Emails $emails) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function saveSite(Request $request): JsonResponse
    {
        $this->setup->saveSite($request->all());

        return response()->json($this->payload());
    }

    public function addSender(Request $request): JsonResponse
    {
        return $this->refusals(function () use ($request) {
            $this->setup->add($request->all(), $request->user()->email);

            return response()->json($this->payload(), 201);
        });
    }

    public function updateSender(Request $request, string $id): JsonResponse
    {
        return $this->refusals(function () use ($request, $id) {
            $this->setup->update($id, $request->all(), $request->user()->email);

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

    /** One test email to the signed-in staff member through the senders in order, kept in the recent list. */
    public function test(Request $request): JsonResponse
    {
        if (! $this->emails->on()) {
            return response()->json(['message' => __('emails.no_sender')], 409);
        }
        $email = $this->emails->send('test', $request->user()->email);

        return response()->json($this->present($email));
    }

    private function refusals(\Closure $act): JsonResponse|Response
    {
        try {
            return $act();
        } catch (EmailRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function payload(): array
    {
        $from = $this->senders->from();
        $fields = fn (array $fs) => array_map(fn (Field $f) => $f->toArray(), $fs);

        return [
            'on' => $this->senders->on(),
            'siteUrl' => $this->senders->siteUrl(),
            'fromAddress' => $from['address'],
            'fromName' => $from['name'],
            'maxSenders' => config('kit.email.max_senders'),
            'drivers' => array_map(fn (string $id) => ['id' => $id, 'name' => __("emails.drivers.{$id}.name"),
                'fields' => $fields($this->senders->driver($id)->fields([]))], array_keys(config('kit.email.drivers'))),
            'senders' => array_map(function (array $s) use ($fields) {
                $driver = $this->senders->driver($s['driver']);

                return ['id' => $s['id'], 'driver' => $s['driver'], 'driverName' => __("emails.drivers.{$s['driver']}.name"),
                    'label' => $driver->label($s['config']), 'fields' => $fields($driver->fields($s['config']))];
            }, $this->senders->all()),
            'recent' => OutgoingEmail::query()->orderByDesc('created_at')->orderByDesc('id')->limit(config('kit.email.recent'))->get()
                ->map($this->present(...))->all(),
        ];
    }

    private function present(OutgoingEmail $e): array
    {
        return [
            'id' => $e->id, 'kind' => $e->kind, 'kindName' => __("emails.kind_names.{$e->kind}"), 'to' => $e->to_address,
            'status' => $e->status, 'attempts' => $e->attempts, 'lastError' => $e->last_error,
            'createdAt' => $e->created_at->toIso8601String(), 'sentAt' => $e->sent_at?->toIso8601String(),
        ];
    }
}
