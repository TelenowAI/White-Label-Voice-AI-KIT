<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Captcha\Captcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Bot check (contract/kit-api.yaml getCaptchaSettings, saveCaptchaSettings): the provider (or none), its site
 * key, whether a secret is saved (never the secret), the forms it guards — with the choices for each.
 *
 * Not here: the check itself (App\Kit\Captcha\Captcha, EnsureCaptcha).
 */
final class CaptchaController
{
    public function __construct(private readonly Captcha $captcha) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->captcha->current()));
    }

    public function save(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->captcha->save($request->all())));
    }

    private function payload(array $current): array
    {
        return $current + [
            'providers' => array_map(fn (string $id) => ['value' => $id, 'label' => __("captcha.providers.{$id}")], array_keys(config('kit.captcha.providers'))),
            'formChoices' => array_map(fn (string $id) => ['value' => $id, 'label' => __("captcha.forms.{$id}")], config('kit.captcha.forms')),
        ];
    }
}
