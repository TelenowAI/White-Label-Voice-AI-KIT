<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Email\Emails;
use App\Kit\Oidc\OidcProviders;
use App\Kit\Pricing\Pricing;
use App\Kit\Signup\SignupRefused;
use App\Kit\Signup\Signups;
use App\Kit\Signup\SignupSettings;
use App\Models\Signup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Sign-ups (contract/kit-api.yaml getSignups, saveSignupSettings, approveSignup, rejectSignup): how people sign
 * up (who may, the ways in, the operator's own form fields, the terms, the trial credit) with what each choice needs
 * (email on; pricing in force for a trial credit); the sign-ups waiting for approval, approving one (its account is made)
 * or refusing one; and the recent sign-ups with the answers they gave, and how each came (an email, a phone instead, a
 * sign-in provider) — never a password, a link or a code.
 *
 * Not here: the public form (SignupController), the wizard's step (SignupStep).
 */
final class SignupsController
{
    public function __construct(
        private readonly SignupSettings $settings,
        private readonly Signups $signups,
        private readonly Emails $emails,
        private readonly Pricing $pricing,
        private readonly OidcProviders $providers,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->settings->save($request->all());

        return response()->json($this->payload());
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->with($id, function (Signup $s) use ($request) {
            $this->signups->approve($s, $request->user());

            return response()->json($this->payload());
        });
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $values = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->with($id, function (Signup $s) use ($request, $values) {
            $this->signups->reject($s, $request->user(), $values['reason'] ?? null);

            return response()->json($this->payload());
        });
    }

    private function with(string $id, \Closure $act): JsonResponse
    {
        $signup = Signup::query()->find($id);
        if ($signup === null) {
            return response()->json(['message' => __('signup.no_signup')], 404);
        }
        try {
            return $act($signup);
        } catch (SignupRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function payload(): array
    {
        $c = config('kit.signup');
        $current = $this->settings->current();

        return [
            'settings' => $current,
            'options' => ['modes' => $c['modes'], 'methods' => $c['methods'], 'fieldTypes' => $c['field_types'], 'maxFields' => $c['max_fields']],
            'emailOn' => $this->emails->on(),
            'currency' => $this->pricing->inForce() ? $this->pricing->settings()['currency'] : null,
            'open' => $this->settings->open(),
            'waiting' => Signup::query()->where('status', Signup::WAITING)->orderBy('verified_at')->get()->map($this->present(...))->all(),
            'recent' => Signup::query()->where('status', '!=', Signup::WAITING)->orderByDesc('created_at')->orderByDesc('id')
                ->limit($c['recent'])->get()->map($this->present(...))->all(),
        ];
    }

    private function present(Signup $s): array
    {
        return [
            'id' => $s->id, 'email' => $s->email, 'phone' => $s->phone, 'name' => $s->name, 'accountName' => $s->account_name, 'status' => $s->status,
            'provider' => $s->provider_id === null ? null : ($this->providers->find($s->provider_id)['name'] ?? __('signup.provider_removed')),
            'answers' => (object) ($s->answers ?? []), 'termsVersion' => $s->terms_version, 'reason' => $s->reason,
            'workspaceId' => $s->workspace_id, 'createdAt' => $s->created_at->toIso8601String(),
            'verifiedAt' => $s->verified_at?->toIso8601String(), 'decidedAt' => $s->decided_at?->toIso8601String(),
        ];
    }
}
