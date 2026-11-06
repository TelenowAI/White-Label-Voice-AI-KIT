<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin → Pricing: the currency, the USD rate and the default plan, and price lists in the pricing spec's format —
 * refused with what is wrong (the format, another currency, a fallback that does not exist or loops), never deleted
 * while a customer, another list or the default uses them, and the currency fixed once it is used.
 */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->staff = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'a long password 9', 'role' => User::ROLE_OWNER]);
    }

    /** @param array<string, mixed> $definition */
    private function list(string $id, array $over = [], string $name = 'Standard'): array
    {
        return ['name' => $name, 'definition' => $over + [
            'id' => $id, 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60,
            'rules' => [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']]],
        ]];
    }

    private function save(string $id, array $body): TestResponse
    {
        return $this->actingAs($this->staff)->putJson("/api/kit/admin/pricing/lists/{$id}", $body);
    }

    private function settings(array $values): TestResponse
    {
        return $this->actingAs($this->staff)->putJson('/api/kit/admin/pricing', $values);
    }

    public function test_staff_set_the_currency_rate_and_default_plan_and_save_lists(): void
    {
        $this->actingAs($this->staff)->getJson('/api/kit/admin/pricing')->assertOk()
            ->assertExactJson(['currency' => null, 'usdRate' => null, 'defaultPlan' => null, 'lowBalanceAt' => null, 'inForce' => false, 'lists' => []]);
        $this->save('standard', $this->list('standard'))->assertStatus(422)->assertJsonPath('errors.definition.0', __('pricing.currency_first'));

        $this->settings(['currency' => 'INR', 'usdRate' => '83.25'])->assertOk()->assertJsonPath('inForce', false);
        $this->save('standard', $this->list('standard'))->assertOk()->assertJsonPath('id', 'standard')->assertJsonPath('customers', 0)
            ->assertJsonPath('definition.rules.0.price', ['markupPercent' => '40']);
        $this->settings(['currency' => 'INR', 'usdRate' => '83.25', 'defaultPlan' => 'standard'])->assertOk()
            ->assertJsonPath('inForce', true)->assertJsonPath('defaultPlan', 'standard')->assertJsonPath('lists.0.name', 'Standard');

        $this->save('standard', $this->list('standard', ['decimals' => 0], 'Standard (whole rupees)'))->assertOk()
            ->assertJsonPath('definition.decimals', 0)->assertJsonPath('name', 'Standard (whole rupees)');
    }

    public function test_a_list_is_refused_with_what_is_wrong_with_it(): void
    {
        $this->settings(['currency' => 'INR', 'usdRate' => '83.25']);
        $fee = $this->list('fees', ['rules' => [['id' => 'rent', 'match' => ['item' => 'fee'], 'price' => ['perMinute' => '1']]]]);
        $this->save('fees', $fee)->assertStatus(422)
            ->assertJsonPath('errors.definition', ['Rule 1: `price` is one of markupPercent, fixed for a fee.']);
        // `{}` reaches the check as an object, not as an empty list.
        $this->actingAs($this->staff)->call('PUT', '/api/kit/admin/pricing/lists/empty', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"name":"Empty","definition":{"id":"empty","currency":"INR","decimals":2,"rounding":"half-up","billingIncrementSeconds":60,"rules":{}}}')
            ->assertStatus(422)->assertJsonPath('errors.definition', ['`rules` is a list.']);
        $this->save('other', $this->list('standard'))->assertStatus(422)->assertJsonPath('errors.definition', ["The list's `id` is the one in its address (other)."]);
        $this->save('usd', $this->list('usd', ['currency' => 'USD']))->assertStatus(422)
            ->assertJsonPath('errors.definition', [__('pricing.one_currency', ['currency' => 'INR'])]);
        $this->save('pro', $this->list('pro', ['extends' => 'base']))->assertStatus(422)
            ->assertJsonPath('errors.definition', [__('pricing.extends_unknown', ['list' => 'pro', 'missing' => 'base'])]);
        $this->save('base', $this->list('base'))->assertOk();
        $this->save('pro', $this->list('pro', ['extends' => 'base']))->assertOk();
        $this->save('base', $this->list('base', ['extends' => 'pro']))->assertStatus(422)
            ->assertJsonPath('errors.definition.0', __('pricing.extends_cycle', ['list' => 'base']));
        $this->save('nameless', ['name' => ' '] + $this->list('nameless'))->assertStatus(422)->assertJsonValidationErrors('name');
        $this->settings(['currency' => 'INR', 'usdRate' => '0'])->assertStatus(422)->assertJsonValidationErrors('usdRate');
        $this->settings(['currency' => 'INR', 'usdRate' => '83', 'defaultPlan' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('defaultPlan');
    }

    public function test_the_currency_is_fixed_once_a_list_uses_it(): void
    {
        $this->settings(['currency' => 'INR', 'usdRate' => '83.25']);
        $this->save('standard', $this->list('standard'))->assertOk();
        $this->settings(['currency' => 'USD', 'usdRate' => '1'])->assertStatus(422)
            ->assertJsonPath('errors.currency', [__('pricing.currency_fixed')]);
    }

    public function test_a_list_in_use_is_never_deleted(): void
    {
        $this->settings(['currency' => 'INR', 'usdRate' => '83.25']);
        foreach (['base', 'pro', 'spare'] as $id) {
            $this->save($id, $this->list($id, $id === 'pro' ? ['extends' => 'base'] : []))->assertOk();
        }
        $this->settings(['currency' => 'INR', 'usdRate' => '83.25', 'defaultPlan' => 'pro']);
        $acme = Workspace::create(['name' => 'Acme', 'status' => 'active', 'sync_state' => 'synced']);
        $acme->forceFill(['price_list_id' => 'spare'])->save();

        $this->actingAs($this->staff)->deleteJson('/api/kit/admin/pricing/lists/pro')->assertStatus(409)->assertExactJson(['message' => __('pricing.in_use_default')]);
        $this->actingAs($this->staff)->deleteJson('/api/kit/admin/pricing/lists/base')->assertStatus(409)
            ->assertExactJson(['message' => __('pricing.in_use_extends', ['lists' => 'pro'])]);
        $this->actingAs($this->staff)->deleteJson('/api/kit/admin/pricing/lists/spare')->assertStatus(409)->assertExactJson(['message' => __('pricing.in_use_customers')]);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/pricing')->assertJsonPath('lists.2.customers', 1);
        $acme->forceFill(['price_list_id' => null])->save();
        $this->actingAs($this->staff)->deleteJson('/api/kit/admin/pricing/lists/spare')->assertNoContent();
        $this->actingAs($this->staff)->deleteJson('/api/kit/admin/pricing/lists/spare')->assertNotFound();
    }

    public function test_only_staff_set_prices(): void
    {
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $acme = Workspace::create(['name' => 'Acme', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $acme->id, 'user_id' => $ana->id, 'role' => 'owner']);
        $this->actingAs($ana)->getJson('/api/kit/admin/pricing')->assertForbidden();
        $this->actingAs($ana)->putJson('/api/kit/admin/pricing', ['currency' => 'INR', 'usdRate' => '1'])->assertForbidden();
        $this->assertNull(app(Settings::class)->get('pricing.currency'));
    }
}
