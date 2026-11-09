<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Invoices\CustomerInvoices;
use App\Kit\Settings\Settings;
use App\Models\CustomerInvoice;
use App\Models\OutgoingEmail;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PriceList;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Customers' invoices (App\Kit\Invoices\CustomerInvoices; contract/kit-api.yaml listAccountInvoices, getAccountInvoice, getBillingDetails,
 * setBillingDetails, getInvoiceSettings, setInvoiceSettings, listCustomerInvoices, getCustomerInvoice): one per payment credited, numbered without
 * gaps per year, the tax included in the amount paid, the operator's and the customer's details kept as they were, and the pages that show them.
 * Payments are bank transfers staff confirm, the way PaymentsTest credits them.
 */
class CustomerInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $ana;

    private User $ben;

    private Workspace $acme;

    private PaymentGateway $bank;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['message' => 'down'], 503)]); // the customer's spend limit at Telenow: only waits
        $settings = app(Settings::class);
        $settings->put('brand.name', 'Acme Voice');
        $settings->put('brand.support_email', 'help@acme-voice.example');
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2,
            'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => []]]);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@dental.example', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/payments/gateways', ['driver' => 'manual', 'name' => 'Bank transfer (NEFT)', 'enabled' => true,
            'values' => ['instructions' => 'Pay to Acme Voice Pvt Ltd']])->assertCreated();
        $this->bank = PaymentGateway::query()->where('driver', 'manual')->sole();
    }

    /** A top-up by bank transfer, confirmed by staff for this amount. */
    private function paid(string $amount, ?User $by = null, ?Workspace $in = null): Payment
    {
        $by ??= $this->ana;
        if ($in !== null) {
            $this->actingAs($by)->withHeader('X-Kit-Workspace', $in->id);
        }
        $id = $this->actingAs($by)->postJson('/api/kit/console/payments', ['gatewayId' => $this->bank->id, 'amount' => $amount])->assertCreated()->json('payment.id');
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/confirm", ['amount' => $amount])->assertOk();

        return Payment::query()->findOrFail($id);
    }

    private function settings(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff)->putJson('/api/kit/admin/invoice-settings', $over + ['prefix' => 'AV/', 'name' => 'Acme Voice Pvt Ltd',
            'address' => "12 Residency Road\nBengaluru 560025", 'taxNumber' => '29ABCDE1234F1Z5', 'email' => 'billing@acme-voice.example', 'taxName' => 'GST', 'taxRate' => '18']);
    }

    public function test_a_credited_payment_gets_one_invoice_with_the_tax_it_includes_and_both_sides_details(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $this->settings()->assertOk()->assertJsonPath('prefix', 'AV/')->assertJsonPath('taxRate', '18');
        $this->actingAs($this->ana)->putJson('/api/kit/console/billing-details', ['name' => 'Acme Dental Clinics LLP', 'address' => 'MG Road, Pune', 'taxNumber' => '27AAAAA0000A1Z5'])
            ->assertOk()->assertJsonPath('name', 'Acme Dental Clinics LLP');
        $payment = $this->paid('1180.00');
        $invoice = CustomerInvoice::query()->sole();
        $page = $this->actingAs($this->ben)->getJson("/api/kit/console/invoices/{$invoice->id}")->assertOk();
        $page->assertExactJson([
            'id' => $invoice->id, 'number' => 'AV/2026-000001', 'issuedAt' => '2026-11-09T10:00:00+00:00', 'currency' => 'INR',
            'description' => __('invoices.line', ['account' => 'Acme Dental', 'brand' => 'Acme Voice']),
            'amount' => '1180.00', 'net' => '1000.00', 'tax' => ['name' => 'GST', 'rate' => '18', 'amount' => '180.00'],
            'seller' => ['name' => 'Acme Voice Pvt Ltd', 'address' => "12 Residency Road\nBengaluru 560025", 'taxNumber' => '29ABCDE1234F1Z5', 'email' => 'billing@acme-voice.example'],
            'buyer' => ['name' => 'Acme Dental Clinics LLP', 'address' => 'MG Road, Pune', 'taxNumber' => '27AAAAA0000A1Z5', 'email' => 'ana@dental.example'],
            'paidWith' => 'Bank transfer (NEFT)', 'refunded' => null,
        ]);
        $this->assertSame($payment->id, $invoice->payment_id);
        // Once per payment, however often it is told it was paid.
        app(CustomerInvoices::class)->issue($payment->fresh());
        $this->assertSame(1, CustomerInvoice::query()->count());
        // A later change of details leaves it as it was; the next carries them.
        $this->settings(['name' => 'Acme Voice India Pvt Ltd', 'taxRate' => '5'])->assertOk();
        $this->actingAs($this->ana)->putJson('/api/kit/console/billing-details', ['name' => null, 'address' => null, 'taxNumber' => null])->assertOk();
        $this->paid('105.00');
        $this->assertSame('Acme Voice Pvt Ltd', $invoice->fresh()->seller['name']);
        $this->actingAs($this->ana)->getJson("/api/kit/console/invoices/{$invoice->id}")->assertJsonPath('seller.name', 'Acme Voice Pvt Ltd')
            ->assertJsonPath('buyer.name', 'Acme Dental Clinics LLP')->assertJsonPath('tax.rate', '18');
        $next = CustomerInvoice::query()->where('sequence', 2)->sole();
        $this->assertSame(['AV/2026-000002', '100.00', '5.00', 'Acme Voice India Pvt Ltd', 'Acme Dental', null],
            [$next->number, $next->net, $next->tax, $next->seller['name'], $next->buyer['name'], $next->buyer['taxNumber']]);
        $this->travelBack();
    }

    public function test_the_tax_is_rounded_to_the_currencys_places_and_none_means_the_amount_alone(): void
    {
        $this->settings(['taxRate' => '18'])->assertOk();
        $this->paid('1000.00');
        $this->assertSame(['1000.00', '847.46', '152.54'], [($i = CustomerInvoice::query()->sole())->amount, $i->net, $i->tax], '1000 × 100 ÷ 118 = 847.457…');
        $this->settings(['taxName' => null, 'taxRate' => null])->assertOk();
        $this->paid('500.00');
        $plain = CustomerInvoice::query()->where('sequence', 2)->sole();
        $this->assertSame(['500.00', '500.00', null, null, null], [$plain->amount, $plain->net, $plain->tax, $plain->tax_name, $plain->tax_rate]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/invoices/{$plain->id}")->assertJsonPath('tax', null);
        $this->settings(['taxRate' => '0'])->assertOk();
        $this->paid('20.00');
        $this->assertNull(CustomerInvoice::query()->where('sequence', 3)->sole()->tax, 'a rate of 0 is no tax');
    }

    public function test_numbers_have_no_gaps_and_start_again_each_year(): void
    {
        $this->travelTo('2026-12-31 23:00:00');
        $this->paid('10.00');
        $this->paid('20.00');
        $this->travelTo('2027-01-01 00:30:00');
        $this->paid('30.00');
        $this->travelBack();
        $this->assertSame(['INV-2026-000001', 'INV-2026-000002', 'INV-2027-000001'], CustomerInvoice::query()->orderBy('issued_at')->pluck('number')->all(),
            'the default prefix while the operator has set none');
        $this->assertSame([[2026, 2], [2027, 1]], DB::table('invoice_counters')->orderBy('year')->get()->map(fn ($r) => [(int) $r->year, (int) $r->last])->all());
    }

    public function test_the_operators_brand_stands_in_for_details_not_given(): void
    {
        $this->paid('50.00');
        $i = CustomerInvoice::query()->sole();
        $this->assertSame(['name' => 'Acme Voice', 'address' => null, 'taxNumber' => null, 'email' => 'help@acme-voice.example'], $i->seller);
        $this->assertSame(['name' => 'Acme Dental', 'address' => null, 'taxNumber' => null, 'email' => 'ana@dental.example'], $i->buyer);
    }

    public function test_no_invoice_for_money_not_credited_and_a_test_payment(): void
    {
        $id = $this->actingAs($this->ana)->postJson('/api/kit/console/payments', ['gatewayId' => $this->bank->id, 'amount' => '75.00'])->assertCreated()->json('payment.id');
        $this->assertSame(0, CustomerInvoice::query()->count(), 'a transfer not yet confirmed');
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame(0, CustomerInvoice::query()->count());
        $test = Payment::query()->findOrFail($id)->replicate()->forceFill(['id' => (string) Str::uuid(), 'workspace_id' => null, 'status' => Payment::PAID, 'paid_at' => now()]);
        $test->save();
        $this->assertNull(app(CustomerInvoices::class)->issue($test));
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame(0, CustomerInvoice::query()->count(), 'a staff test payment credits no one');
    }

    public function test_a_payment_credited_without_its_invoice_gets_it_from_the_payments_job(): void
    {
        $payment = $this->paid('60.00');
        CustomerInvoice::query()->delete();
        DB::table('invoice_counters')->delete();
        $this->artisan('kit:payments')->assertSuccessful()->expectsOutputToContain('1 invoice(s) issued late');
        $this->assertSame([$payment->id, 'INV-'.now()->utc()->format('Y').'-000001'], [CustomerInvoice::query()->sole()->payment_id, CustomerInvoice::query()->sole()->number]);
        $this->artisan('kit:payments')->assertSuccessful()->expectsOutputToContain('0 invoice(s) issued late');
    }

    public function test_the_payers_email_links_to_the_invoice(): void
    {
        $senders = app(Senders::class);
        $senders->putSiteUrl('https://console.acme.example');
        $senders->putFrom('hello@acme.example', null);
        $senders->put([['id' => 'down', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']]]);
        $this->paid('99.00');
        $email = OutgoingEmail::query()->where('kind', 'payment_received')->sole();
        $this->assertStringContainsString('https://console.acme.example/console/invoices/'.CustomerInvoice::query()->sole()->id, $email->body['text']);
    }

    public function test_a_refund_is_shown_beside_the_invoice_which_stays_as_issued(): void
    {
        $payment = $this->paid('200.00');
        $payment->forceFill(['refunded' => '50.00'])->save();
        $invoice = CustomerInvoice::query()->sole();
        $this->actingAs($this->ana)->getJson("/api/kit/console/invoices/{$invoice->id}")->assertJsonPath('refunded', '50.00')->assertJsonPath('amount', '200.00');
        $this->actingAs($this->ana)->getJson('/api/kit/console/invoices')->assertJsonPath('invoices.0.refunded', '50.00');
    }

    public function test_the_account_sees_its_own_invoices_and_its_managers_change_its_billing_details(): void
    {
        $this->paid('10.00');
        $this->travel(1)->minutes();
        $this->paid('20.00');
        $list = $this->actingAs($this->ben)->getJson('/api/kit/console/invoices')->assertOk();
        $this->assertSame(['20.00', '10.00'], array_column($list->json('invoices'), 'amount'), 'newest first');
        $list->assertJsonPath('page', 1)->assertJsonPath('total', 2)->assertJsonPath('perPage', config('kit.admin.per_page'));
        config(['kit.admin.per_page' => 1]);
        $this->actingAs($this->ben)->getJson('/api/kit/console/invoices?page=2')->assertJsonPath('invoices.0.amount', '10.00')->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'invoices');
        $this->actingAs($this->staff)->getJson("/api/kit/admin/customers/{$this->acme->id}/invoices?page=2")->assertJsonPath('invoices.0.amount', '10.00')
            ->assertJsonPath('page', 2);
        $this->actingAs($this->ben)->getJson('/api/kit/console/invoices?page=nope')->assertJsonPath('page', 1)->assertJsonPath('invoices.0.amount', '20.00');
        config(['kit.admin.per_page' => 50]);
        $this->assertSame(['id', 'number', 'issuedAt', 'currency', 'amount', 'refunded'], array_keys($list->json('invoices.0')));
        $list->assertJsonPath('canEdit', false)->assertJsonPath('billingDetails', ['name' => null, 'address' => null, 'taxNumber' => null]);
        $this->actingAs($this->ben)->putJson('/api/kit/console/billing-details', ['name' => 'X', 'address' => null, 'taxNumber' => null])->assertForbidden()
            ->assertJsonPath('message', __('invoices.managers_only'));
        $this->actingAs($this->ana)->getJson('/api/kit/console/billing-details')->assertOk()->assertJsonPath('canEdit', true);
        $this->actingAs($this->ana)->putJson('/api/kit/console/billing-details', ['name' => str_repeat('x', 121), 'address' => null, 'taxNumber' => null])->assertStatus(422)
            ->assertJsonValidationErrors('name');
        $this->actingAs($this->ana)->putJson('/api/kit/console/billing-details', ['name' => '  Acme  '])->assertStatus(422)->assertJsonValidationErrors(['address', 'taxNumber']);
        $this->actingAs($this->ana)->putJson('/api/kit/console/billing-details', ['name' => '  Acme Dental LLP  ', 'address' => '', 'taxNumber' => ' '])->assertOk()
            ->assertExactJson(['name' => 'Acme Dental LLP', 'address' => null, 'taxNumber' => null, 'canEdit' => true]);
        // Trimmed, and empty is none, also when not sent through a form (the framework does it for those).
        $this->assertSame(['name' => 'Acme', 'address' => null, 'taxNumber' => null],
            app(CustomerInvoices::class)->setBillingDetails($this->acme, ['name' => "  Acme \n", 'address' => '   ', 'taxNumber' => '']));
        // Another account's invoice is not this one's.
        $other = Workspace::create(['name' => 'Bright Smiles', 'status' => 'active', 'sync_state' => 'synced']);
        $cy = User::create(['name' => 'Cy', 'email' => 'cy@bright.example', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $cy->id, 'role' => 'owner']);
        $mine = CustomerInvoice::query()->first();
        $this->actingAs($cy)->getJson("/api/kit/console/invoices/{$mine->id}")->assertNotFound();
        $this->actingAs($cy)->getJson('/api/kit/console/invoices')->assertOk()->assertJsonPath('invoices', []);
        // A member of both accounts opens it from the payment email whichever account they have open.
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $cy->id, 'role' => 'member']);
        $this->actingAs($cy)->withSession(['kit.workspace' => $other->id])->getJson('/api/kit/console/invoices')->assertJsonPath('invoices', []);
        $this->actingAs($cy)->withSession(['kit.workspace' => $other->id])->getJson("/api/kit/console/invoices/{$mine->id}")->assertOk()->assertJsonPath('number', $mine->number);
        $this->actingAs($this->ana)->getJson('/api/kit/console/invoices/not-an-id')->assertNotFound();
        // Staff looking at the customer's console (they belong to no account) open its invoices too.
        $this->actingAs($this->staff)->postJson("/api/kit/admin/customers/{$this->acme->id}/view-as")->assertOk();
        $this->actingAs($this->staff)->getJson("/api/kit/console/invoices/{$mine->id}")->assertOk()->assertJsonPath('number', $mine->number);
        $this->actingAs($this->staff)->postJson('/api/kit/session/view-as/stop')->assertOk();
    }

    public function test_the_operators_invoice_settings_are_checked_and_staff_see_a_customers_invoices(): void
    {
        $this->actingAs($this->staff)->getJson('/api/kit/admin/invoice-settings')->assertOk()->assertExactJson(
            ['prefix' => 'INV-', 'name' => null, 'address' => null, 'taxNumber' => null, 'email' => null, 'taxName' => null, 'taxRate' => null, 'sequenceDigits' => 6]);
        config(['kit.invoices.sequence_digits' => 4]);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/invoice-settings')->assertJsonPath('sequenceDigits', 4);
        config(['kit.invoices.sequence_digits' => 6]);
        foreach ([['taxRate' => '18.5'], ['taxRate' => '100'], ['taxRate' => '0.25']] as $ok) {
            $this->settings($ok)->assertOk();
        }
        foreach ([['taxRate' => '101'], ['taxRate' => '18.555'], ['taxRate' => '-1'], ['taxRate' => '100.5'], ['taxRate' => 'GST']] as $bad) {
            $this->settings($bad)->assertStatus(422)->assertJsonPath('errors.taxRate.0', __('invoices.rate_shape'));
        }
        $this->settings(['taxName' => null])->assertStatus(422)->assertJsonValidationErrors('taxName');
        $this->settings(['taxRate' => null])->assertStatus(422)->assertJsonValidationErrors('taxRate');
        $this->settings(['prefix' => 'INV 2026'])->assertStatus(422)->assertJsonValidationErrors('prefix');
        $this->settings(['email' => 'not an email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->actingAs($this->staff)->putJson('/api/kit/admin/invoice-settings', ['prefix' => 'AV-'])->assertStatus(422);
        $this->settings(['prefix' => null])->assertOk()->assertJsonPath('prefix', '');
        $this->paid('10.00');
        $this->assertStringStartsWith(now()->utc()->format('Y').'-', CustomerInvoice::query()->sole()->number, 'an empty prefix');
        $invoice = CustomerInvoice::query()->sole();
        $this->actingAs($this->staff)->getJson("/api/kit/admin/customers/{$this->acme->id}/invoices")->assertOk()->assertJsonPath('invoices.0.id', $invoice->id);
        $this->actingAs($this->staff)->getJson("/api/kit/admin/invoices/{$invoice->id}")->assertOk()->assertJsonPath('customerId', $this->acme->id)
            ->assertJsonPath('number', $invoice->number);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/customers/'.Str::uuid().'/invoices')->assertNotFound();
        $support = User::create(['name' => 'Sam', 'email' => 'sam@voice.example', 'password' => 'a long password 1', 'role' => 'support']);
        $this->actingAs($support)->getJson('/api/kit/admin/invoice-settings')->assertForbidden();
        $this->actingAs($support)->getJson("/api/kit/admin/invoices/{$invoice->id}")->assertOk(); // customers_read
        $this->actingAs($this->ana)->getJson('/api/kit/admin/invoice-settings')->assertForbidden();
    }
}
