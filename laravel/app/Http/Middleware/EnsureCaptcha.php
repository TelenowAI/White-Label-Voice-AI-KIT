<?php

namespace App\Http\Middleware;

use App\Kit\Captcha\Captcha;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A public form's bot check (App\Kit\Captcha\Captcha): when the operator guards this form (`EnsureCaptcha:<form>`, one
 * of config('kit.captcha.forms')), the request must carry `captchaToken` the provider confirms; else 422 on
 * `captchaToken`. Unguarded forms pass untouched. Applied in routes/api.php, after the throttle.
 */
final class EnsureCaptcha
{
    public function __construct(private readonly Captcha $captcha) {}

    public function handle(Request $request, Closure $next, string $form): Response
    {
        if ($this->captcha->guards($form) && ! $this->captcha->passes($request->input('captchaToken'), $request->ip())) {
            return response()->json(['message' => __('captcha.failed'), 'errors' => ['captchaToken' => [__('captcha.failed')]]], 422);
        }

        return $next($request);
    }
}
