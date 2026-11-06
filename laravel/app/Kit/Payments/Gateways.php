<?php

namespace App\Kit\Payments;

use App\Kit\Email\Senders;
use App\Kit\Pricing\Pricing;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PriceList;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The ways to pay the operator switched on (SELF_HOSTED_KIT_PLAN.md §4.6 a), each a driver from
 * config('kit.payments.drivers') with its saved config, kept in `payment_gateways`:
 * - adding or changing one checks its form (the driver's own fields; a secret left empty is kept) and, for an online
 *   gateway, its keys with one harmless call — a gateway that refuses them is not saved;
 * - a customer is offered the enabled ones that take the kit's currency and serve its plan, in the operator's order;
 *   a gateway in test mode (whose payments move no money) cannot be enabled, and is never offered;
 * - a removed one stays in the table, out of every list, so its late refunds and disputes still land.
 * Up to config('kit.payments.max_gateways'). Links (a gateway's webhook, a payment's return) use the site address the
 * operator confirmed (Senders::link), never a request's Host.
 *
 * Not here: paying (Payments), the forms around it (Admin\PaymentsController, PaymentsStep).
 */
final class Gateways
{
    public function __construct(private readonly Pricing $pricing, private readonly Senders $senders) {}

    /** @return list<string> */
    public function driverIds(): array
    {
        return array_keys(config('kit.payments.drivers'));
    }

    public function driver(string $id): PaymentDriver
    {
        $class = config("kit.payments.drivers.{$id}");
        if (! is_string($class)) {
            throw new InvalidArgumentException("Unknown payment driver {$id}");
        }

        return app($class);
    }

    /**
     * A gateway's driver, or null when it is no longer installed (taken out of config('kit.payments.drivers') while
     * payments through it remain: they are still listed, but nothing more is done through it).
     */
    public function driverOf(PaymentGateway $gateway): ?PaymentDriver
    {
        return in_array($gateway->driver, $this->driverIds(), true) ? $this->driver($gateway->driver) : null;
    }

    /** @return Collection<int, PaymentGateway> every gateway not removed whose driver is installed, in order */
    public function listed(): Collection
    {
        return PaymentGateway::query()->listed()->get()->filter(fn (PaymentGateway $g) => in_array($g->driver, $this->driverIds(), true))->values();
    }

    public function find(string $id): ?PaymentGateway
    {
        return Str::isUuid($id) ? $this->listed()->firstWhere('id', $id) : null;
    }

    /** Whether a gateway takes this currency. */
    public function takes(PaymentGateway $gateway, ?string $currency): bool
    {
        $currencies = $this->driver($gateway->driver)->currencies();

        return $currency !== null && ($currencies === null || in_array($currency, $currencies, true));
    }

    /** @return Collection<int, PaymentGateway> what this customer may pay with, in the operator's order */
    public function offered(Workspace $workspace): Collection
    {
        $currency = $this->pricing->settings()['currency'];
        $plan = $this->pricing->planOf($workspace);

        return $this->listed()->filter(fn (PaymentGateway $g) => $g->enabled && $g->mode === PaymentGateway::LIVE && $this->takes($g, $currency)
            && ($g->plans === null || in_array($plan, $g->plans, true)))->values();
    }

    /** Add a gateway after the others. */
    public function add(array $input): PaymentGateway
    {
        if ($this->listed()->count() >= config('kit.payments.max_gateways')) {
            throw new PaymentRefused(409, __('payments.too_many', ['max' => config('kit.payments.max_gateways')]));
        }
        $driverId = Validator::make($input, ['driver' => ['required', 'string', Rule::in($this->driverIds())]], [], $this->names())->validate()['driver'];
        $gateway = new PaymentGateway;
        $gateway->forceFill(['driver' => $driverId, 'config' => [], 'position' => (int) PaymentGateway::query()->max('position') + 1]);
        $this->fill($gateway, $input + ['name' => __("payments.drivers.{$driverId}.name")], true);
        $gateway->save();

        return $gateway;
    }

    /** Change a gateway: only what `$input` carries (its driver stays; a secret left empty is kept). */
    public function update(PaymentGateway $gateway, array $input): PaymentGateway
    {
        $this->fill($gateway, $input, false);
        $gateway->save();

        return $gateway;
    }

    public function remove(PaymentGateway $gateway): void
    {
        $gateway->forceFill(['enabled' => false, 'removed_at' => now()])->save();
    }

    /** @param  list<string>  $ids  every listed gateway's id, in the new order */
    public function order(array $ids): void
    {
        $all = $this->listed();
        if (count($ids) !== $all->count() || count(array_unique($ids)) !== count($ids) || array_diff($ids, $all->pluck('id')->all()) !== []) {
            throw new PaymentRefused(422, __('payments.order_mismatch'));
        }
        foreach (array_values($ids) as $i => $id) {
            PaymentGateway::query()->whereKey($id)->update(['position' => $i + 1]);
        }
    }

    /** Where this gateway sends its events. */
    public function notifyUrl(PaymentGateway $gateway): string
    {
        return $this->senders->link("api/kit/payments/webhooks/{$gateway->id}");
    }

    /** @return list<string> what this gateway needs of this person that their account does not have */
    public function asks(PaymentGateway $gateway, User $user): array
    {
        return array_values(array_filter($this->driver($gateway->driver)->payerNeeds(), fn (string $field) => ($user->{$field} ?? '') === ''));
    }

    /** Where the payer comes back to from the gateway. */
    public function returnUrl(Payment $payment): string
    {
        return $this->senders->link("api/kit/payments/{$payment->id}/return");
    }

    /** Check `$input` against the gateway's driver and put it on the gateway; an online gateway's keys are tried. */
    private function fill(PaymentGateway $gateway, array $input, bool $adding): void
    {
        $driver = $this->driver($gateway->driver);
        $all = $adding ? 'required' : 'sometimes';
        $rules = [
            'name' => [$all, 'string', 'max:'.config('kit.payments.name_max_length')],
            'mode' => $driver->online() ? [$all, 'string', Rule::in([PaymentGateway::TEST, PaymentGateway::LIVE])] : ['prohibited'],
            'methods' => [$driver->methods() === [] ? 'prohibited' : 'sometimes', 'array', 'min:1'],
            'methods.*' => ['string', 'distinct', Rule::in($driver->methods())],
            'plans' => ['sometimes', 'nullable', 'array'],
            'plans.*' => ['string', 'distinct', Rule::in(PriceList::query()->pluck('id')->all())],
            'enabled' => ['sometimes', 'boolean'],
            'values' => [$all, 'array'],
        ];
        $names = $this->names();
        foreach ($driver->fields($gateway->config ?? []) as $field) {
            $names["values.{$field->name}"] = $field->label;
        }
        $saved = $gateway->config ?? [];
        $values = $input['values'] ?? null;
        if (is_array($values)) {
            foreach ($driver->secrets() as $secret) {
                if (($values[$secret] ?? '') === '' && ($saved[$secret] ?? '') !== '') {
                    $values[$secret] = $saved[$secret];
                }
            }
            $input['values'] = $values;
            foreach ($driver->rules() as $name => $fieldRules) {
                $rules["values.{$name}"] = $fieldRules;
            }
        }
        $checked = Validator::make($input, $rules, [], $names)->validate();

        $gateway->forceFill(array_filter([
            'name' => $checked['name'] ?? null,
            'mode' => $driver->online() ? ($checked['mode'] ?? null) : PaymentGateway::LIVE,
            'methods' => array_key_exists('methods', $checked) ? array_values($checked['methods']) : ($adding ? $driver->methods() : null),
            'enabled' => array_key_exists('enabled', $checked) ? (bool) $checked['enabled'] : null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('plans', $checked)) {
            $gateway->plans = $checked['plans'] === null || $checked['plans'] === [] ? null : array_values($checked['plans']);
        }
        if (is_array($values)) {
            $gateway->config = array_intersect_key($checked['values'], $driver->rules());
        }
        if ($gateway->enabled && $gateway->mode === PaymentGateway::TEST) {
            throw new PaymentRefused(422, __('payments.live_to_enable'));
        }
        if ($driver->online() && ($adding || is_array($values) || $gateway->isDirty('mode'))) {
            try {
                $driver->verify($gateway);
            } catch (PaymentFailed $e) {
                throw new PaymentRefused(422, __('payments.keys_refused', ['reason' => Str::limit($e->getMessage(), config('kit.payments.error_max_length'))]));
            }
        }
    }

    private function names(): array
    {
        return array_map(fn (string $f) => __("payments.fields.{$f}"), ['driver' => 'driver', 'name' => 'name', 'mode' => 'mode', 'methods' => 'methods', 'plans' => 'plans', 'enabled' => 'enabled']);
    }
}
