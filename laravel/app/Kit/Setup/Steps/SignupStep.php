<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Settings\Settings;
use App\Kit\Setup\Field;
use App\Kit\Setup\SetupStep;
use App\Kit\Signup\SignupSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step: sign-ups (SELF_HOSTED_KIT_PLAN.md §4.1.8). Who may sign up — one of config('kit.signup.modes'), closed
 * to start with — and, for the domains mode, the email domains (comma or space separated). Every mode but closed needs
 * email, so with none set up the step says so on the mode field (SignupSettings::save).
 *
 * Not here: the ways in, the form's own fields, the terms, the trial credit (Admin → Sign-ups).
 */
final class SignupStep extends SetupStep
{
    public function __construct(private readonly Settings $settings, private readonly SignupSettings $signup) {}

    public function id(): string
    {
        return 'signup';
    }

    public function done(): bool
    {
        return $this->settings->has(SignupSettings::MODE);
    }

    public function fields(Request $request, bool $mayRead): array
    {
        $current = $mayRead ? $this->signup->current() : ['mode' => SignupSettings::CLOSED, 'domains' => []];

        return [
            new Field('mode', __('signup.settings.mode'), 'select', true, $current['mode'], null,
                array_map(fn (string $m) => ['value' => $m, 'label' => __("signup.modes.{$m}")], config('kit.signup.modes'))),
            new Field('domains', __('signup.settings.domains'), 'text', true, implode(', ', $current['domains']), $this->text('domains_help'), [],
                ['field' => 'mode', 'values' => [SignupSettings::ONLY_DOMAINS]]),
        ];
    }

    public function submit(Request $request, array $input): void
    {
        $domains = preg_split('/[\s,]+/', (string) ($input['domains'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        try {
            $this->signup->save(['mode' => $input['mode'] ?? null, 'domains' => $domains]);
        } catch (ValidationException $e) {
            // `domains.0` and the like are the one domains field here.
            throw ValidationException::withMessages(collect($e->errors())
                ->mapToGroups(fn ($m, $f) => [str_starts_with($f, 'domains') ? 'domains' : $f => $m])
                ->map(fn ($groups) => Arr::flatten($groups->all()))->all());
        }
    }
}
