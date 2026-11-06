<?php

namespace App\Kit\Pricing;

use App\Kit\Settings\Settings;
use App\Models\PriceList;
use App\Models\WalletEntry;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

/**
 * The operator's prices (admin → Pricing; SELF_HOSTED_KIT_PLAN.md §4.6 b-c): the price lists (one per plan, in the
 * pricing spec's format), the one currency every list bills in, the rate that turns Telenow's USD into it, and the
 * default plan of a customer with none of its own. Customers are charged only once all three are set (inForce());
 * until then the wallet is not in force at all — nothing is charged, limited or paused.
 *
 * Settings: `pricing.currency`, `pricing.usd_rate` (a decimal string: the currency per 1 USD, stored with each charge so
 * history is never recomputed), `pricing.default_plan`.
 *
 * Not here: the arithmetic (PricingEngine), charging (App\Kit\Wallet\CallCharges).
 */
final class Pricing
{
    public function __construct(private readonly Settings $settings, private readonly PricingEngine $engine) {}

    /** @return array{currency: ?string, usdRate: ?string, defaultPlan: ?string} */
    public function settings(): array
    {
        return [
            'currency' => $this->settings->get('pricing.currency'),
            'usdRate' => $this->settings->get('pricing.usd_rate'),
            'defaultPlan' => $this->settings->get('pricing.default_plan'),
        ];
    }

    /** Whether customers are charged: a currency, a rate and a default plan that exists. */
    public function inForce(): bool
    {
        $s = $this->settings();

        return $s['currency'] !== null && $s['usdRate'] !== null && $s['defaultPlan'] !== null
            && PriceList::query()->whereKey($s['defaultPlan'])->exists();
    }

    /** The customer's plan: its own, else the default. */
    public function planOf(Workspace $workspace): ?string
    {
        return $workspace->price_list_id ?? $this->settings()['defaultPlan'];
    }

    /** @return list<array<string, mixed>> every list, as the engine takes them */
    public function lists(): array
    {
        return PriceList::query()->orderBy('id')->get()->map(fn (PriceList $l) => $l->definition)->all();
    }

    /**
     * Price an item for the customer at the current rate (the engine's answer, with the plan used).
     *
     * @return array{currency: string, usdRate: string, lines: list<array>, total: string, plan: string}
     */
    public function price(Workspace $workspace, array $item): array
    {
        if (! $this->inForce()) {
            throw new PricingRefused('pricing_not_set_up', 'kit');
        }
        $plan = $this->planOf($workspace);

        return $this->engine->price($this->lists(), $plan, $this->settings()['usdRate'], $item) + ['plan' => $plan];
    }

    /** Save a list (new or changed). Throws a ValidationException naming what is wrong with it. */
    public function saveList(string $id, string $name, mixed $definition): PriceList
    {
        $problems = PriceListFormat::problems($definition);
        $definition = $problems === [] ? json_decode(json_encode($definition), true) : null;
        if ($definition !== null && $definition['id'] !== $id) {
            $problems[] = "The list's `id` is the one in its address ({$id}).";
        }
        $currency = $this->settings()['currency'];
        if ($definition !== null && $currency === null) {
            $problems[] = __('pricing.currency_first');
        } elseif ($definition !== null && $definition['currency'] !== $currency) {
            $problems[] = __('pricing.one_currency', ['currency' => $currency]);
        }
        if ($problems === []) {
            $problems = $this->chainProblems([$id => $definition] + array_column($this->lists(), null, 'id'));
        }
        if (trim($name) === '') {
            throw ValidationException::withMessages(['name' => [__('pricing.name_required')]]);
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['definition' => $problems]);
        }

        return PriceList::query()->updateOrCreate(['id' => $id], ['name' => trim($name), 'definition' => $definition]);
    }

    /** Delete a list no customer, no other list and not the default uses. Answers why not, or null once deleted. */
    public function deleteList(string $id): ?string
    {
        if ($this->settings()['defaultPlan'] === $id) {
            return __('pricing.in_use_default');
        }
        if (Workspace::query()->where('price_list_id', $id)->exists()) {
            return __('pricing.in_use_customers');
        }
        $extending = array_values(array_filter($this->lists(), fn (array $l) => ($l['extends'] ?? null) === $id));
        if ($extending !== []) {
            return __('pricing.in_use_extends', ['lists' => implode(', ', array_column($extending, 'id'))]);
        }
        PriceList::query()->whereKey($id)->delete();

        return null;
    }

    /** Set the currency, the rate and the default plan. Throws a ValidationException naming what is wrong. */
    public function updateSettings(array $input): void
    {
        $errors = [];
        $currency = $input['currency'] ?? null;
        if (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'][] = __('pricing.currency_code');
        } elseif ($currency !== $this->settings()['currency']
            && (WalletEntry::query()->exists() || PriceList::query()->get()->contains(fn (PriceList $l) => $l->definition['currency'] !== $currency))) {
            $errors['currency'][] = __('pricing.currency_fixed');
        }
        $rate = $input['usdRate'] ?? null;
        if (! is_string($rate) || ! preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/', $rate) || preg_match('/^0(\.0+)?$/', $rate)) {
            $errors['usdRate'][] = __('pricing.rate_positive');
        }
        $plan = $input['defaultPlan'] ?? null;
        if ($plan !== null && ! (is_string($plan) && PriceList::query()->whereKey($plan)->exists())) {
            $errors['defaultPlan'][] = __('pricing.unknown_plan');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $this->settings->put('pricing.currency', $currency);
        $this->settings->put('pricing.usd_rate', $rate);
        $this->settings->put('pricing.default_plan', $plan);
    }

    /** @param array<string, array> $lists @return list<string> each list's chain that does not resolve */
    private function chainProblems(array $lists): array
    {
        $problems = [];
        foreach (array_keys($lists) as $start) {
            $seen = [];
            for ($id = $start; $id !== null; $id = $lists[$id]['extends'] ?? null) {
                if (! isset($lists[$id])) {
                    $problems[] = __('pricing.extends_unknown', ['list' => $start, 'missing' => $id]);
                    break;
                }
                if (isset($seen[$id])) {
                    $problems[] = __('pricing.extends_cycle', ['list' => $start]);
                    break;
                }
                $seen[$id] = true;
            }
        }

        return array_values(array_unique($problems));
    }
}
