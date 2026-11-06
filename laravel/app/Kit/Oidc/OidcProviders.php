<?php

namespace App\Kit\Oidc;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The OpenID Connect providers customers may sign in with (SELF_HOSTED_KIT_PLAN.md §4.7 social login), in the order
 * the sign-in page shows them, each with the operator's own OAuth client — kept in settings, encrypted (the client
 * secrets). A provider is a preset from config('kit.oidc.presets') (Google, Microsoft) or any issuer the operator names (https,
 * or http on this machine only, for testing). Adding or changing one reads the issuer's OpenID configuration first, so
 * a mistyped issuer is caught at once. The address to register at the provider is redirectUri().
 *
 * Not here: signing in (OidcSignIn), the page (Admin\SsoController).
 */
final class OidcProviders
{
    public const LIST = 'oidc.providers';

    private const LOOPBACK = ['127.0.0.1', 'localhost', '[::1]'];

    public function __construct(private readonly Settings $settings, private readonly Discovery $discovery, private readonly Senders $senders) {}

    /** @return list<array{id: string, preset: ?string, name: string, issuer: ?string, clientId: string, clientSecret: string, enabled: bool}> */
    public function all(): array
    {
        return array_values(array_filter($this->settings->get(self::LIST, []), fn ($p) => is_array($p) && is_string($p['id'] ?? null)));
    }

    /** @return list<array> the ones customers may use, in order */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), fn (array $p) => $p['enabled']));
    }

    public function find(string $id): ?array
    {
        return collect($this->all())->firstWhere('id', $id);
    }

    /** The issuer a provider signs in with: its preset's, or the operator's. */
    public function issuer(array $provider): string
    {
        return $provider['preset'] !== null ? config("kit.oidc.presets.{$provider['preset']}.issuer") : $provider['issuer'];
    }

    /** A provider's own description of itself (Discovery), with the issuer its preset says it names. */
    public function configuration(array $provider, bool $fresh = false): array
    {
        return $this->discovery->configuration($this->issuer($provider), $fresh, $this->preset($provider)['tenant_issuer'] ?? null);
    }

    /** Whether this provider vouches for the email in these claims: by its preset's own rule, else OpenID Connect's `email_verified`. */
    public function emailVerified(array $provider, array $claims): bool
    {
        $rule = $this->preset($provider)['verified_email'] ?? null;
        if ($rule === null) {
            return in_array($claims['email_verified'] ?? false, [true, 'true'], true);
        }

        return in_array($claims['tid'] ?? null, $rule['tenants'], true) || in_array($claims[$rule['claim']] ?? false, [true, 'true', 1, '1'], true);
    }

    private function preset(array $provider): array
    {
        return $provider['preset'] !== null ? (array) config("kit.oidc.presets.{$provider['preset']}") : [];
    }

    /** The address the operator registers at the provider, where people come back after signing in there. */
    public function redirectUri(): string
    {
        return $this->senders->link('api/kit/oidc/callback');
    }

    public function add(array $input): array
    {
        $all = $this->all();
        if (count($all) >= config('kit.oidc.max_providers')) {
            throw new OidcRefused(409, __('oidc.too_many', ['max' => config('kit.oidc.max_providers')]));
        }
        $provider = $this->checked($input + ['enabled' => true], null);
        $this->put([...$all, $provider]);

        return $provider;
    }

    /** Change a provider (a secret left empty is kept). */
    public function update(string $id, array $input): array
    {
        $all = $this->all();
        $at = $this->at($all, $id);
        $all[$at] = $this->checked($input, $all[$at]);
        $this->put($all);

        return $all[$at];
    }

    public function remove(string $id): void
    {
        $all = $this->all();
        array_splice($all, $this->at($all, $id), 1);
        $this->put($all);
    }

    /** @param  list<string>  $ids  every provider's id, in the new order */
    public function order(array $ids): void
    {
        $all = $this->all();
        $byId = array_column($all, null, 'id');
        if (count($ids) !== count($all) || count(array_unique($ids)) !== count($ids) || array_diff($ids, array_keys($byId)) !== []) {
            throw new OidcRefused(422, __('oidc.order_mismatch'));
        }
        $this->put(array_map(fn (string $id) => $byId[$id], $ids));
    }

    private function put(array $providers): void
    {
        $this->settings->put(self::LIST, array_values($providers), true);
    }

    private function checked(array $input, ?array $saved): array
    {
        $names = array_map(fn (string $f) => __("oidc.fields.{$f}"),
            ['preset' => 'preset', 'issuer' => 'issuer', 'name' => 'name', 'clientId' => 'clientId', 'clientSecret' => 'clientSecret', 'enabled' => 'enabled']);
        $values = Validator::make($input, [
            'preset' => ['present', 'nullable', 'string', Rule::in(array_keys(config('kit.oidc.presets')))],
            'issuer' => ['required_without:preset', 'nullable', 'string', 'max:500', 'url:http,https'],
            'name' => ['required', 'string', 'max:'.config('kit.oidc.name_max_length')],
            'clientId' => ['required', 'string', 'max:500'],
            'clientSecret' => [$saved === null ? 'required' : 'nullable', 'string', 'max:2000'],
            'enabled' => ['sometimes', 'boolean'],
        ], [], $names)->validate();
        $provider = [
            'id' => $saved['id'] ?? (string) Str::uuid(),
            'preset' => $values['preset'] ?? null,
            'name' => $values['name'],
            'issuer' => ($values['preset'] ?? null) === null ? rtrim((string) $values['issuer'], '/') : null,
            'clientId' => $values['clientId'],
            'clientSecret' => ($values['clientSecret'] ?? '') !== '' ? $values['clientSecret'] : (string) ($saved['clientSecret'] ?? ''),
            'enabled' => (bool) ($values['enabled'] ?? $saved['enabled'] ?? true),
        ];
        if ($provider['issuer'] !== null) {
            $url = parse_url($provider['issuer']);
            if (($url['scheme'] ?? '') !== 'https' && ! in_array($url['host'] ?? '', self::LOOPBACK, true)) {
                throw ValidationException::withMessages(['issuer' => [__('oidc.issuer_https')]]);
            }
        }
        try {
            $this->configuration($provider, fresh: true);
        } catch (OidcFailed $e) {
            throw ValidationException::withMessages([$provider['preset'] === null ? 'issuer' : 'preset' => [
                __('oidc.issuer_unreadable', ['reason' => Str::limit($e->getMessage(), config('kit.oidc.error_max_length'))])]]);
        }

        return $provider;
    }

    private function at(array $all, string $id): int
    {
        foreach ($all as $i => $p) {
            if ($p['id'] === $id) {
                return $i;
            }
        }
        throw new OidcRefused(404, __('oidc.unknown_provider'));
    }
}
