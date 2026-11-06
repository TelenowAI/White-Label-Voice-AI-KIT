<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Email\EmailRefused;
use App\Kit\Email\Senders;
use App\Kit\Email\SenderSetup;
use App\Kit\Settings\Settings;
use App\Kit\Setup\Field;
use App\Kit\Setup\SetupStep;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step: email (SELF_HOSTED_KIT_PLAN.md §4.1.7, §4.7). The site's address (every link in an email opens it;
 * the form starts from the address the wizard is open on, for the owner to confirm), who emails come from, and the
 * first sender: one of config('kit.email.drivers'), its form drawn from the driver's own fields (named
 * `<driver>.<field>`, shown while that driver is chosen) — or "not now", which finishes the step with no email: invite
 * links are then shown to whoever makes them, and nobody can reset a password. A sender is saved only after it has
 * sent the owner a test email (SenderSetup); its failure is shown on the driver field in the sender's own words.
 *
 * Not here: more senders, their order, the recent emails (Admin → Email).
 */
final class EmailStep extends SetupStep
{
    private const NONE = 'none';

    public function __construct(private readonly Settings $settings, private readonly Senders $senders, private readonly SenderSetup $setup) {}

    public function id(): string
    {
        return 'email';
    }

    public function done(): bool
    {
        return $this->senders->all() !== [] || $this->settings->get(Senders::SKIPPED) === true;
    }

    public function fields(Request $request, bool $mayRead): array
    {
        $drivers = array_keys(config('kit.email.drivers'));
        $first = $mayRead ? ($this->senders->all()[0] ?? null) : null;
        $skipped = $mayRead && $this->settings->get(Senders::SKIPPED) === true;
        $from = $mayRead ? $this->settings->get(Senders::FROM, []) : [];
        $whenSending = ['field' => 'driver', 'values' => $drivers];

        $fields = [
            new Field('siteUrl', __('emails.fields.siteUrl'), 'url', true,
                $mayRead && $this->settings->has(Senders::SITE) ? $this->senders->siteUrl() : $request->getSchemeAndHttpHost(), __('emails.help.siteUrl')),
            new Field('driver', __('emails.fields.driver'), 'select', true, $first['driver'] ?? ($skipped ? self::NONE : $drivers[0]), null, [
                ...array_map(fn (string $id) => ['value' => $id, 'label' => __("emails.drivers.{$id}.name")], $drivers),
                ['value' => self::NONE, 'label' => $this->text('none')],
            ]),
            new Field('fromAddress', __('emails.fields.fromAddress'), 'email', true, $from['address'] ?? null, null, [], $whenSending),
            new Field('fromName', __('emails.fields.fromName'), 'text', false, $from['name'] ?? null, __('emails.help.fromName'), [], $whenSending),
        ];
        foreach ($drivers as $id) {
            $config = ($first['driver'] ?? null) === $id ? $first['config'] : [];
            foreach ($this->senders->driver($id)->fields($config) as $f) {
                $fields[] = new Field("{$id}.{$f->name}", $f->label, $f->type, $f->required, $f->value, $f->help, $f->options,
                    ['field' => 'driver', 'values' => [$id]], $f->secretSet);
            }
        }

        return $fields;
    }

    public function submit(Request $request, array $input): void
    {
        $input = Arr::undot($input);
        $drivers = array_keys(config('kit.email.drivers'));
        $driver = Validator::make($input, ['driver' => ['required', 'string', Rule::in([...$drivers, self::NONE])]],
            [], ['driver' => __('emails.fields.driver')])->validate()['driver'];

        if ($driver === self::NONE) {
            $values = Validator::make($input, ['siteUrl' => ['required', 'string', 'url:http,https', 'max:255']],
                [], ['siteUrl' => __('emails.fields.siteUrl')])->validate();
            $this->senders->putSiteUrl($values['siteUrl']);
            $this->senders->put([]);
            $this->settings->put(Senders::SKIPPED, true);

            return;
        }
        $this->setup->saveSite($input);
        try {
            $this->setup->putFirst($driver, is_array($input[$driver] ?? null) ? $input[$driver] : [], $request->user()->email);
        } catch (EmailRefused $e) {
            throw ValidationException::withMessages(['driver' => [$e->getMessage()]]);
        } catch (ValidationException $e) {
            // The driver names its fields bare; this form names them `<driver>.<field>`.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $f) => ["{$driver}.{$f}" => $m])->all());
        }
        $this->settings->put(Senders::SKIPPED, false);
    }
}
