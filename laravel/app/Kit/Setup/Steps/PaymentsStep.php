<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Payments\Gateways;
use App\Kit\Payments\PaymentRefused;
use App\Kit\Settings\Settings;
use App\Kit\Setup\Field;
use App\Kit\Setup\Installation;
use App\Kit\Setup\SetupStep;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step: payments (SELF_HOSTED_KIT_PLAN.md §4.1.9). The first way customers pay: one of
 * config('kit.payments.drivers'), the name customers see, an online gateway's mode, and its form drawn from the
 * driver's own fields (named `<driver>.<field>`, shown while that driver is chosen) — or "not now", which finishes the
 * step with none (customers then get credit only by hand). An online gateway's keys are tried before it is saved
 * (Gateways), and it is enabled only in live mode. Submitting again changes that first gateway, or adds one when a
 * different driver is chosen.
 *
 * Not here: more gateways, their rules and order, the top-up rules, payments (Admin → Payments).
 */
final class PaymentsStep extends SetupStep
{
    public const SKIPPED = 'payments.skipped';

    private const NONE = 'none';

    public function __construct(private readonly Settings $settings, private readonly Gateways $gateways, private readonly Installation $installation) {}

    public function id(): string
    {
        return 'payments';
    }

    public function done(): bool
    {
        return $this->first() !== null || $this->settings->get(self::SKIPPED) === true;
    }

    public function fields(Request $request, bool $mayRead): array
    {
        $drivers = $this->gateways->driverIds();
        $online = array_values(array_filter($drivers, fn (string $id) => $this->gateways->driver($id)->online()));
        $first = $mayRead ? $this->first() : null;
        $skipped = $mayRead && $this->settings->get(self::SKIPPED) === true;

        $fields = [
            new Field('driver', __('payments.fields.driver'), 'select', true, $first->driver ?? ($skipped ? self::NONE : $drivers[0]), null, [
                ...array_map(fn (string $id) => ['value' => $id, 'label' => __("payments.drivers.{$id}.name")], $drivers),
                ['value' => self::NONE, 'label' => $this->text('none')],
            ]),
            new Field('name', __('payments.fields.name'), 'text', false, $first?->name, null, [], ['field' => 'driver', 'values' => $drivers]),
        ];
        if ($online !== []) {
            $fields[] = new Field('mode', __('payments.fields.mode'), 'select', true, $first->mode ?? PaymentGateway::TEST, null,
                array_map(fn (string $m) => ['value' => $m, 'label' => __("payments.modes.{$m}")], [PaymentGateway::TEST, PaymentGateway::LIVE]),
                ['field' => 'driver', 'values' => $online]);
        }
        foreach ($drivers as $id) {
            $config = $first?->driver === $id ? $first->config : [];
            foreach ($this->gateways->driver($id)->fields($config) as $f) {
                $fields[] = new Field("{$id}.{$f->name}", $f->label, $f->type, $f->required, $f->value, $f->help, $f->options,
                    ['field' => 'driver', 'values' => [$id]], $f->secretSet);
            }
        }

        return $fields;
    }

    public function submit(Request $request, array $input): void
    {
        $input = Arr::undot($input);
        $driver = Validator::make($input, ['driver' => ['required', 'string', Rule::in([...$this->gateways->driverIds(), self::NONE])]],
            [], ['driver' => __('payments.fields.driver')])->validate()['driver'];
        if ($driver === self::NONE) {
            $this->settings->put(self::SKIPPED, true);

            return;
        }
        $online = $this->gateways->driver($driver)->online();
        $form = array_filter([
            'name' => ($input['name'] ?? '') === '' ? __("payments.drivers.{$driver}.name") : $input['name'],
            'mode' => $online ? ($input['mode'] ?? null) : null,
            'values' => is_array($input[$driver] ?? null) ? $input[$driver] : [],
            'enabled' => ! $online || ($input['mode'] ?? null) === PaymentGateway::LIVE,
        ], fn ($v) => $v !== null);
        $first = $this->first();
        try {
            if ($first !== null && $first->driver === $driver) {
                $this->gateways->update($first, $form);
            } else {
                $this->gateways->add(['driver' => $driver] + $form);
            }
        } catch (PaymentRefused $e) {
            throw ValidationException::withMessages(['driver' => [$e->getMessage()]]);
        } catch (ValidationException $e) {
            // Gateways names a driver's fields `values.<field>`; this form names them `<driver>.<field>`.
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(fn ($m, $f) => [str_starts_with($f, 'values.') ? $driver.substr($f, 6) : $f => $m])->all());
        }
        $this->settings->put(self::SKIPPED, false);
    }

    /** The first gateway, once the database is set up (the wizard lists this step before it is). */
    private function first(): ?PaymentGateway
    {
        return $this->installation->tableUsable('payment_gateways') ? $this->gateways->listed()->first() : null;
    }
}
