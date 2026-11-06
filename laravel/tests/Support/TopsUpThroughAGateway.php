<?php

namespace Tests\Support;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Models\PaymentGateway;
use App\Models\PriceList;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * What a real gateway's tests share: an installed kit with staff, a customer in the test's currency, Telenow answering from recordings and
 * the gateway answering from the test's own fake of its documented API. Not here: the gateway's answers (each test class).
 *
 * @phpstan-require-extends \Tests\TestCase
 */
trait TopsUpThroughAGateway
{
    private const SITE = 'https://voice.acme.example';

    private User $staff;

    private User $ana;

    private Workspace $acme;

    /** @var list<array{method: string, path: string, query: string, body: array, request: Request}> what the kit sent the gateway */
    private array $sent = [];

    /** @param \Closure(Request): mixed $gateway answers the gateway's calls; null: not its call */
    private function installWith(\Closure $gateway, string $currency = 'INR'): void
    {
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        app(Senders::class)->putSiteUrl(self::SITE);
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => $currency, 'decimals' => 2,
            'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markup' => '1.5']]]]]);
        $settings->put('pricing.currency', $currency);
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => 'vai_test_customer_acme'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        RecordedTelenow::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $gateway($r) ?? $recorded($r));
    }

    private function record(Request $r): void
    {
        $this->sent[] = ['method' => $r->method(), 'path' => (string) parse_url($r->url(), PHP_URL_PATH), 'query' => (string) parse_url($r->url(), PHP_URL_QUERY),
            'body' => $r->data(), 'request' => $r];
    }

    /** @return list<array{method: string, path: string, query: string, body: array, request: Request}> */
    private function sentTo(string $method, string $path): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['method'] === $method && $s['path'] === $path));
    }

    private function add(string $driver, string $mode, array $values): TestResponse
    {
        return $this->actingAs($this->staff)->postJson('/api/kit/admin/payments/gateways',
            ['driver' => $driver, 'name' => 'Pay online', 'mode' => $mode, 'enabled' => $mode === 'live', 'values' => $values]);
    }

    /** @param  array<string, string>  $payer  what the page asked of them for this payment */
    private function topUp(PaymentGateway $gateway, string $amount, ?User $as = null, array $payer = []): TestResponse
    {
        return $this->actingAs($as ?? $this->ana)->postJson('/api/kit/console/payments', ['gatewayId' => $gateway->id, 'amount' => $amount] + ($payer === [] ? [] : ['payer' => $payer]));
    }

    /** The payer's browser coming back from the gateway. */
    private function comeBack(string $paymentId, array $query = []): TestResponse
    {
        return $this->get("/api/kit/payments/{$paymentId}/return".($query === [] ? '' : '?'.http_build_query($query)))->assertStatus(303);
    }

    /** @return list<array{string, string, string}> kind, amount and reference of the customer's wallet entries */
    private function entries(): array
    {
        return WalletEntry::query()->where('workspace_id', $this->acme->id)->orderBy('id')->get()
            ->map(fn (WalletEntry $e) => [$e->kind, $e->amount, $e->reference])->all();
    }

    private function balance(): string
    {
        return $this->acme->fresh()->balance;
    }
}
