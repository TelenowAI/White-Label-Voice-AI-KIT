<?php

namespace App\Providers;

use App\Kit\Health\JobRuns;
use App\Kit\Setup\Installation;
use App\Kit\Sms\PhoneNumber;
use App\Kit\Website\Theme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request: it remembers which tables it has found.
        $this->app->scoped(Installation::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The website's templates: the child theme's first, then the default theme (App\Kit\Website\Theme).
        View::addNamespace('theme', Theme::paths());

        // When each scheduled job last ran, for Admin → Voice platform (App\Kit\Health\JobRuns).
        Event::listen(ScheduledTaskStarting::class, [JobRuns::class, 'started']);
        Event::listen(ScheduledTaskFinished::class, [JobRuns::class, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [JobRuns::class, 'failed']);

        // Sign-in tries, per email from one address (config('kit.auth.sign_in_per_minute')). The refusal has the
        // kit API's error shape, `{message}` (contract/kit-api.yaml signIn 429). Before the database is set up there
        // is nobody to sign in as and nowhere to count tries (the limiter's store was chosen at boot, before
        // PrepareInstallation could move it off the database), so nothing is counted.
        RateLimiter::for('kit-sign-in', fn (Request $request) => ! $this->app->make(Installation::class)->tableUsable('users')
            ? Limit::none()
            : Limit::perMinute(config('kit.auth.sign_in_per_minute'))
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        // Invite acceptances, per address (config('kit.team.accept_per_minute')): a token is a secret worth guessing at.
        // The same database guard as signing in.
        // Password reset: per email and address (asking for a link), and per address (asking, and using links).
        RateLimiter::for('kit-password', function (Request $request) {
            if (! $this->app->make(Installation::class)->tableUsable('users')) {
                return Limit::none();
            }
            $limits = [Limit::perMinute(config('kit.auth.reset_requests_per_minute_per_address'))->by('address|'.$request->ip())];
            if ($request->filled('email')) {
                $limits[] = Limit::perMinute(config('kit.auth.reset_requests_per_minute'))
                    ->by('email|'.Str::lower((string) $request->input('email')).'|'.$request->ip());
            }

            return array_map(fn (Limit $limit) => $limit->response(
                fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)), $limits);
        });

        // Signing up and asking for a sign-in link: per address, and per email (when the request names one).
        RateLimiter::for('kit-signup', function (Request $request) {
            if (! $this->app->make(Installation::class)->tableUsable('users')) {
                return Limit::none();
            }
            $limits = [Limit::perMinute(config('kit.signup.per_minute_per_address'))->by('signup-address|'.$request->ip())];
            if ($request->filled('email')) {
                $limits[] = Limit::perMinute(config('kit.signup.per_minute_per_email'))->by('signup-email|'.Str::lower((string) $request->input('email')));
            }

            return array_map(fn (Limit $limit) => $limit->response(
                fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)), $limits);
        });

        // Asking for a phone code: per address, and per number (normalized, so spacing it differently is the same
        // number; App\Kit\Sms\PhoneNumber) when the request names one. Using one is slowed per address only: each code
        // has its own few tries (config('kit.phone.code_attempts')).
        RateLimiter::for('kit-phone-verify', fn (Request $request) => ! $this->app->make(Installation::class)->tableUsable('users')
            ? Limit::none()
            : Limit::perMinute(config('kit.phone.per_minute_per_address'))->by('phone-verify|'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));
        RateLimiter::for('kit-phone-code', function (Request $request) {
            if (! $this->app->make(Installation::class)->tableUsable('users')) {
                return Limit::none();
            }
            $limits = [Limit::perMinute(config('kit.phone.per_minute_per_address'))->by('phone-address|'.$request->ip())];
            $number = PhoneNumber::normalize($request->input('phone'));
            if ($number !== null) {
                $limits[] = Limit::perMinute(config('kit.phone.per_minute_per_number'))->by('phone-number|'.$number);
            }

            return array_map(fn (Limit $limit) => $limit->response(
                fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)), $limits);
        });

        // Placing calls from the browser, per member (config('kit.softphone.calls_per_minute')). Only signed-in members reach it.
        RateLimiter::for('kit-browser-call', fn (Request $request) => Limit::perMinute(config('kit.softphone.calls_per_minute'))->by('browser-call|'.$request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        // An agent calling one person, per member (config('kit.agent_calls.calls_per_minute')). Only signed-in members reach it.
        RateLimiter::for('kit-stack-check', fn (Request $request) => Limit::perMinute(config('kit.stack_check.per_minute'))->by('stack-check|'.$request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        // The prompt check, per member (config('kit.agents.prompt_check.per_minute')): the console checks as the instructions are written, a few
        // seconds after typing stops.
        RateLimiter::for('kit-prompt-check', fn (Request $request) => Limit::perMinute(config('kit.agents.prompt_check.per_minute'))->by('prompt-check|'.$request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        RateLimiter::for('kit-routing-check', fn (Request $request) => Limit::perMinute(config('kit.routing.checks_per_minute'))->by('routing-check|'.$request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        RateLimiter::for('kit-realtime-preview', fn (Request $request) => Limit::perMinute(config('kit.catalog.realtime.previews_per_minute'))->by('realtime-preview|'.$request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        RateLimiter::for('kit-own-voice', fn (Request $request) => Limit::perMinute(config('kit.own_voices.writes_per_minute'))->by('own-voice|'.$request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        RateLimiter::for('kit-agent-call', fn (Request $request) => Limit::perMinute(config('kit.agent_calls.calls_per_minute'))->by('agent-call|'.$request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        // Starting and finishing a sign-in at an OpenID Connect provider, per address.
        RateLimiter::for('kit-oidc', fn (Request $request) => Limit::perMinute(config('kit.oidc.per_minute_per_address'))->by('oidc|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        // Coming back from a payment gateway, per address: each return makes the kit ask the gateway.
        RateLimiter::for('kit-payment-return', fn (Request $request) => Limit::perMinute(config('kit.payments.returns_per_minute'))
            ->by('payment-return|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));

        RateLimiter::for('kit-invite-accept', fn (Request $request) => ! $this->app->make(Installation::class)->tableUsable('users')
            ? Limit::none()
            : Limit::perMinute(config('kit.team.accept_per_minute'))->by($request->ip())
                ->response(fn (Request $request, array $headers) => response()->json(['message' => __('session.throttled')], 429, $headers)));
    }
}
