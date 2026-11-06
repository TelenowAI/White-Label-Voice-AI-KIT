<?php

namespace App\Kit\Captcha;

use App\Kit\Settings\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The bot check on the public forms (SELF_HOSTED_KIT_PLAN.md §4.5 anti-abuse, §4.7): one provider from
 * config('kit.captcha.providers') — Cloudflare Turnstile, hCaptcha and reCAPTCHA all render a widget the same way and
 * confirm a token with the same `siteverify` call, so each is data (its script, its browser global, its verify
 * address) — with the operator's site key and secret (encrypted), guarding the forms the operator picks from
 * config('kit.captcha.forms'). A token is confirmed with the provider for each guarded request; when the provider
 * cannot be reached the request is refused (fails closed). Saving a provider asks it whether the secret is good.
 *
 * Not here: refusing the request (App\Http\Middleware\EnsureCaptcha), the page (Admin\CaptchaController).
 */
final class Captcha
{
    public const PROVIDER = 'captcha.provider';

    public const SITE_KEY = 'captcha.site_key';

    public const SECRET = 'captcha.secret';

    public const FORMS = 'captcha.forms';

    public function __construct(private readonly Settings $settings) {}

    /** @return array{provider: ?string, siteKey: ?string, secretSet: bool, forms: list<string>} */
    public function current(): array
    {
        $provider = $this->settings->get(self::PROVIDER);

        return [
            'provider' => is_string($provider) && array_key_exists($provider, config('kit.captcha.providers')) ? $provider : null,
            'siteKey' => $this->settings->get(self::SITE_KEY),
            'secretSet' => is_string($this->settings->get(self::SECRET)) && $this->settings->get(self::SECRET) !== '',
            'forms' => array_values(array_intersect(config('kit.captcha.forms'), $this->settings->get(self::FORMS, config('kit.captcha.default_forms')))),
        ];
    }

    /** Whether this form must pass the bot check. */
    public function guards(string $form): bool
    {
        $c = $this->current();

        return $c['provider'] !== null && in_array($form, $c['forms'], true);
    }

    /** What the browser needs to show the widget, or null when no bot check is on. */
    public function forBrowser(): ?array
    {
        $c = $this->current();
        if ($c['provider'] === null) {
            return null;
        }
        $p = config("kit.captcha.providers.{$c['provider']}");

        return ['provider' => $c['provider'], 'script' => $p['script'], 'global' => $p['global'], 'siteKey' => $c['siteKey'], 'forms' => $c['forms']];
    }

    /** Whether the provider confirms this token (false also when it cannot be reached: the check fails closed). */
    public function passes(?string $token, ?string $ip): bool
    {
        $c = $this->current();
        if ($c['provider'] === null) {
            return true;
        }
        if (! is_string($token) || $token === '') {
            return false;
        }

        return ($this->siteverify($c['provider'], (string) $this->settings->get(self::SECRET), $token, $ip)['success'] ?? false) === true;
    }

    /** Save the provider, keys and guarded forms. A secret left empty is kept; a new one is checked with the provider. */
    public function save(array $input): array
    {
        $providers = array_keys(config('kit.captcha.providers'));
        $values = Validator::make($input, [
            'provider' => ['present', 'nullable', 'string', Rule::in($providers)],
            'siteKey' => ['required_with:provider', 'nullable', 'string', 'max:200'],
            'secret' => ['nullable', 'string', 'max:500'],
            'forms' => ['present', 'array'],
            'forms.*' => ['string', 'distinct', Rule::in(config('kit.captcha.forms'))],
        ], [], array_map(fn (string $f) => __("captcha.fields.{$f}"), ['provider' => 'provider', 'siteKey' => 'siteKey', 'secret' => 'secret', 'forms' => 'forms']))->validate();
        $provider = $values['provider'] ?? null;
        $secret = ($values['secret'] ?? '') !== '' ? $values['secret'] : $this->settings->get(self::SECRET);
        if ($provider !== null) {
            if (! is_string($secret) || $secret === '') {
                throw ValidationException::withMessages(['secret' => [__('captcha.secret_needed')]]);
            }
            // A made-up token: the provider answers why it is refused, which says whether the secret is good.
            $answer = $this->siteverify($provider, $secret, 'kit-secret-check', null);
            if ($answer === null) {
                throw ValidationException::withMessages(['provider' => [__('captcha.unreachable')]]);
            }
            if (array_intersect($answer['error-codes'] ?? [], config('kit.captcha.bad_secret_codes')) !== []) {
                throw ValidationException::withMessages(['secret' => [__('captcha.secret_refused')]]);
            }
        }
        $this->settings->put(self::PROVIDER, $provider);
        $this->settings->put(self::SITE_KEY, $provider === null ? null : $values['siteKey']);
        if (($values['secret'] ?? '') !== '') {
            $this->settings->put(self::SECRET, $values['secret'], true);
        }
        $this->settings->put(self::FORMS, array_values(array_intersect(config('kit.captcha.forms'), $values['forms'])));

        return $this->current();
    }

    /** The provider's answer, or null when it could not be reached or did not answer JSON. */
    private function siteverify(string $provider, string $secret, string $token, ?string $ip): ?array
    {
        try {
            $response = Http::asForm()->timeout((int) config('kit.captcha.timeout_seconds'))
                ->post(config("kit.captcha.providers.{$provider}.verify_url"), array_filter(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]));
        } catch (ConnectionException) {
            return null;
        }
        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
