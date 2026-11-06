<?php

namespace App\Kit\Signup;

use App\Kit\Email\Emails;
use App\Kit\Pricing\Pricing;
use App\Kit\Settings\Settings;
use App\Kit\Setup\Field;
use App\Kit\Sms\Sms;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * How people sign up, as the operator set it (SELF_HOSTED_KIT_PLAN.md §4.5): who may (one of config('kit.signup.modes');
 * closed until the operator picks), the ways customers get in (a password, an emailed sign-in link or code, a code to their phone), the
 * operator's own form fields, the terms with their version, and the trial credit (in the pricing currency). Every mode
 * but closed needs email on (addresses are verified by email); a trial credit needs pricing in force.
 *
 * The public form is declared here as fields — the built-in ones (name, email, password when that way is on, account
 * name), the operator's own (`answers.<key>`), and the terms box — and the console draws whatever comes. Two more ways
 * to sign up have the same form without the email and password (owner 11-01): with a phone (a `phone` field instead,
 * confirmed by a texted code; offered while texts and the `phone_code` way in are on, in every open mode but domains,
 * which is about email addresses) and with a sign-in provider (the provider vouched for the address).
 *
 * Not here: taking a sign-up (Signups), sign-in links (SignInLinks), the forms around it (Admin → Sign-ups, SignupStep),
 * the sign-in providers (App\Kit\Oidc\OidcProviders).
 */
final class SignupSettings
{
    /** The modes' meanings; config('kit.signup.modes') lists the ones an operator may pick. */
    public const CLOSED = 'closed';

    public const APPROVAL = 'approval';

    public const ONLY_DOMAINS = 'domains';

    public const MODE = 'signup.mode';

    public const DOMAINS = 'signup.domains';

    public const METHODS = 'signup.methods';

    public const FIELDS = 'signup.fields';

    public const TERMS = 'signup.terms';

    public const TRIAL = 'signup.trial_credit';

    /** The ways to sign up (form, rules): with an email, with a phone, with a sign-in provider. */
    public const BY_EMAIL = 'email';

    public const BY_PHONE = 'phone';

    public const BY_PROVIDER = 'provider';

    private const BUILT_IN = ['name', 'email', 'password', 'accountName', 'terms', 'answers'];

    private const KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    private const DOMAIN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    private const AMOUNT = '/^(0|[1-9][0-9]*)(\.[0-9]{1,8})?$/';

    public function __construct(private readonly Settings $settings, private readonly Emails $emails, private readonly Pricing $pricing, private readonly Sms $sms) {}

    /**
     * @return array{mode: string, domains: list<string>, methods: list<string>,
     *               fields: list<array{key: string, label: string, type: string, required: bool, options: list<string>}>,
     *               terms: ?array{url: string, version: string}, trialCredit: ?string}
     */
    public function current(): array
    {
        $modes = config('kit.signup.modes');
        $mode = $this->settings->get(self::MODE);
        $terms = $this->settings->get(self::TERMS);

        return [
            'mode' => in_array($mode, $modes, true) ? $mode : self::CLOSED,
            'domains' => array_values($this->settings->get(self::DOMAINS, [])),
            'methods' => array_values(array_intersect(config('kit.signup.methods'), $this->settings->get(self::METHODS, config('kit.signup.default_methods')))),
            'fields' => array_values($this->settings->get(self::FIELDS, [])),
            'terms' => is_array($terms) && is_string($terms['url'] ?? null) && is_string($terms['version'] ?? null) ? $terms : null,
            'trialCredit' => $this->settings->get(self::TRIAL),
        ];
    }

    /** Whether the public form takes sign-ups now: a mode other than closed, and email on. */
    public function open(): bool
    {
        return $this->current()['mode'] !== self::CLOSED && $this->emails->on();
    }

    /** Whether this person may use a password: customers while the operator has the way in on, staff always. */
    public function passwordWorksFor(User $user): bool
    {
        return $user->role !== null || $this->uses('password');
    }

    public function uses(string $method): bool
    {
        return in_array($method, $this->current()['methods'], true);
    }

    /**
     * Whether people may sign up with a phone now: sign-ups open in a mode that takes anyone (domains mode takes email
     * addresses), texts on, and the `phone_code` way in on — a code is how such a person signs in again.
     */
    public function byPhone(): bool
    {
        return $this->open() && $this->current()['mode'] !== self::ONLY_DOMAINS && $this->sms->on() && $this->uses('phone_code');
    }

    /** Whether this address may sign up in the current mode. */
    public function allows(string $email): bool
    {
        $current = $this->current();
        if ($current['mode'] !== self::ONLY_DOMAINS) {
            return true;
        }
        $domain = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));

        return in_array($domain, $current['domains'], true);
    }

    /**
     * The public form for one way of signing up (BY_EMAIL, BY_PHONE, BY_PROVIDER); `$name` is suggested in the name field
     * (what a sign-in provider says).
     *
     * @return list<Field>
     */
    public function form(string $way = self::BY_EMAIL, ?string $name = null): array
    {
        $current = $this->current();
        $text = fn (string $key, array $vars = []) => __("signup.form.{$key}", $vars);
        $fields = [new Field('name', $text('name'), 'text', true, $name)];
        if ($way === self::BY_EMAIL) {
            $fields[] = new Field('email', $text('email'), 'email', true);
            if ($this->uses('password')) {
                $fields[] = new Field('password', $text('password'), 'password', true, null, $text('password_help', ['min' => config('kit.auth.password_min_length')]));
            }
        } elseif ($way === self::BY_PHONE) {
            $fields[] = new Field('phone', $text('phone'), 'tel', true, null, $text('phone_help'));
        }
        $fields[] = new Field('accountName', $text('accountName'), 'text', true, null, $text('accountName_help'));
        foreach ($current['fields'] as $f) {
            $fields[] = new Field("answers.{$f['key']}", $f['label'], $f['type'], (bool) $f['required'], null, null,
                array_map(fn (string $o) => ['value' => $o, 'label' => $o], $f['options'] ?? []));
        }
        if ($current['terms'] !== null) {
            $fields[] = new Field('terms', $text('terms', ['version' => $current['terms']['version']]), 'checkbox', true, null, $current['terms']['url']);
        }

        return $fields;
    }

    /**
     * Laravel rules for one way's public form (form()), with each field's name for its messages. A phone is only
     * required here; its shape is App\Kit\Sms\PhoneNumber's to check.
     *
     * @return array{0: array, 1: array<string, string>}
     */
    public function rules(string $way = self::BY_EMAIL): array
    {
        $current = $this->current();
        $max = config('kit.signup.field_max_length');
        $rules = ['name' => ['required', 'string', 'max:120']];
        if ($way === self::BY_EMAIL) {
            $rules['email'] = ['required', 'string', 'email', 'max:255'];
        } elseif ($way === self::BY_PHONE) {
            $rules['phone'] = ['required', 'string', 'max:40'];
        }
        $rules += [
            'accountName' => ['required', 'string', 'max:'.config('kit.customers.name_max_length')],
            'answers' => ['nullable', 'array'],
        ];
        $names = ['name' => __('signup.form.name'), 'email' => __('signup.form.email'), 'accountName' => __('signup.form.accountName'),
            'password' => __('signup.form.password'), 'phone' => __('signup.form.phone'), 'terms' => __('signup.form.terms_name')];
        if ($way === self::BY_EMAIL && $this->uses('password')) {
            $rules['password'] = ['required', 'string', Password::min(config('kit.auth.password_min_length'))];
        }
        foreach ($current['fields'] as $f) {
            $rules["answers.{$f['key']}"] = [$f['required'] ? 'required' : 'nullable', 'string', "max:{$max}",
                ...match ($f['type']) {
                    'email' => ['email'],
                    'url' => ['url:http,https'],
                    'select' => [Rule::in($f['options'] ?? [])],
                    default => [],
                }];
            $names["answers.{$f['key']}"] = $f['label'];
        }
        if ($current['terms'] !== null) {
            $rules['terms'] = ['accepted'];
        }

        return [$rules, $names];
    }

    /** Check and save the operator's settings (Admin → Sign-ups; the wizard sends only `mode` and `domains`). */
    public function save(array $input): void
    {
        $c = config('kit.signup');
        $input += $this->current();
        if (is_array($input['domains'])) {
            $input['domains'] = array_map(fn ($d) => is_string($d) ? mb_strtolower(trim($d)) : $d, $input['domains']);
        }
        $values = Validator::make($input, [
            'mode' => ['required', 'string', Rule::in($c['modes'])],
            'domains' => ['present', 'array'],
            'domains.*' => ['string', 'regex:'.self::DOMAIN],
            'methods' => ['required', 'array', 'min:1'],
            'methods.*' => ['string', Rule::in($c['methods'])],
            'fields' => ['present', 'array', 'max:'.$c['max_fields']],
            'fields.*.key' => ['required', 'string', 'regex:'.self::KEY, Rule::notIn(self::BUILT_IN), 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:120'],
            'fields.*.type' => ['required', 'string', Rule::in($c['field_types'])],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.options' => ['present', 'array'],
            'fields.*.options.*' => ['string', 'max:120'],
            'terms' => ['nullable', 'array'],
            'terms.url' => ['required_with:terms', 'string', 'url:http,https', 'max:2048'],
            'terms.version' => ['required_with:terms', 'string', 'max:40'],
            'trialCredit' => ['nullable', 'string', 'regex:'.self::AMOUNT],
        ], [], array_map(fn (string $k) => __("signup.settings.{$k}"), ['mode' => 'mode', 'domains' => 'domains', 'methods' => 'methods',
            'fields' => 'fields', 'terms.url' => 'terms_url', 'terms.version' => 'terms_version', 'trialCredit' => 'trialCredit']))->validate();

        $domains = array_values(array_unique($values['domains']));
        $errors = array_filter([
            'mode' => $values['mode'] !== self::CLOSED && ! $this->emails->on() ? [__('signup.needs_email')] : null,
            'domains' => $values['mode'] === self::ONLY_DOMAINS && $domains === [] ? [__('signup.needs_domains')] : null,
            'methods' => match (true) {
                in_array('phone_code', $values['methods'], true) && ! $this->sms->on() => [__('signup.needs_sms')],
                in_array('email_code', $values['methods'], true) && ! $this->emails->on() => [__('signup.needs_email_for_codes')],
                default => null,
            },
            'trialCredit' => ($values['trialCredit'] ?? null) !== null && (float) $values['trialCredit'] > 0 && ! $this->pricing->inForce()
                ? [__('signup.needs_pricing')] : null,
        ]);
        foreach ($values['fields'] as $i => $f) {
            if ($f['type'] === 'select' && $f['options'] === []) {
                $errors["fields.{$i}.options"] = [__('signup.needs_options')];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->settings->put(self::MODE, $values['mode']);
        $this->settings->put(self::DOMAINS, $domains);
        $this->settings->put(self::METHODS, array_values(array_intersect($c['methods'], $values['methods'])));
        $this->settings->put(self::FIELDS, array_map(fn (array $f) => ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'],
            'required' => (bool) $f['required'], 'options' => $f['type'] === 'select' ? array_values($f['options']) : []], $values['fields']));
        $this->settings->put(self::TERMS, ($values['terms'] ?? null) !== null ? ['url' => $values['terms']['url'], 'version' => $values['terms']['version']] : null);
        $this->settings->put(self::TRIAL, ($values['trialCredit'] ?? null) !== null && (float) $values['trialCredit'] > 0 ? $values['trialCredit'] : null);
    }
}
