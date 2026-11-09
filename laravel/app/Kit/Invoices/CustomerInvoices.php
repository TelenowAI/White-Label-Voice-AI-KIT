<?php

namespace App\Kit\Invoices;

use App\Kit\Brand\Brand;
use App\Kit\Payments\Money;
use App\Kit\Settings\Settings;
use App\Models\CustomerInvoice;
use App\Models\Payment;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Customers' invoices (console → Wallet → Invoices; Admin → Invoices and a customer's page; contract/kit-api.yaml listAccountInvoices,
 * getAccountInvoice, getBillingDetails, setBillingDetails, getInvoiceSettings, setInvoiceSettings, listCustomerInvoices, getCustomerInvoice). Owner's
 * choices 11-09: one invoice per payment, a page made for printing (or saving as PDF from the browser), a tax line when the operator sets one.
 *
 * - issue(): when a payment is credited to a customer's wallet (App\Kit\Payments\Payments, a gateway's or a bank transfer staff confirmed; never a
 *   test payment): its number — the operator's prefix, the year (UTC) and the next number of that year, without gaps (`invoice_counters`, taken in
 *   the invoice's own transaction) — the amount credited, and, while the operator charges a tax, that amount split into its net and the tax it
 *   includes (net = amount × 100 ÷ (100 + rate), to the currency's places; the tax is the rest). The operator's details (Admin → Invoices; their brand
 *   name and support email when none are given) and the customer's (their billing details, else the account's name; the payer's email) are kept on
 *   it as they are now. Once per payment (a payment credited twice is told so by its unique invoice).
 * - catchUp(): payments credited with no invoice yet (issue() failed, say) are issued theirs; `kit:payments` runs it. A failure here never stops a
 *   payment being credited.
 * - present(): an invoice as the page shows it, with what has been refunded of its payment since (an invoice is never changed: a refund is shown
 *   beside it, not taken from it).
 *
 * Not here: credit notes for refunds; the money (App\Kit\Wallet\Wallet).
 */
final class CustomerInvoices
{
    private const SETTINGS = 'invoices.details';

    public function __construct(private readonly Settings $settings, private readonly Brand $brand) {}

    public function issue(Payment $payment): ?CustomerInvoice
    {
        if ($payment->status !== Payment::PAID || $payment->isTest()) { // a test payment is one with no account
            return null;
        }
        $existing = CustomerInvoice::query()->where('payment_id', $payment->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $amount = BigDecimal::of($payment->received ?? $payment->amount);
        $places = Money::decimals($payment->currency);
        $details = $this->settings();
        $rate = $details['taxRate'] === null ? null : BigDecimal::of($details['taxRate']);
        $net = $rate === null || $rate->isZero() ? $amount : $amount->multipliedBy(100)->dividedBy($rate->plus(100), $places, RoundingMode::HalfUp);
        $workspace = $payment->workspace;
        $at = $payment->paid_at ?? now();
        $year = (int) $at->copy()->utc()->format('Y');
        try {
            return DB::transaction(function () use ($payment, $workspace, $amount, $net, $rate, $details, $at, $year, $places) {
                DB::table('invoice_counters')->insertOrIgnore(['year' => $year, 'last' => 0]);
                $last = (int) DB::table('invoice_counters')->where('year', $year)->lockForUpdate()->value('last');
                $sequence = $last + 1;
                DB::table('invoice_counters')->where('year', $year)->update(['last' => $sequence]);
                $invoice = new CustomerInvoice;
                $invoice->forceFill([
                    'workspace_id' => $workspace->id,
                    'payment_id' => $payment->id,
                    'number' => $details['prefix'].$year.'-'.str_pad((string) $sequence, (int) config('kit.invoices.sequence_digits'), '0', STR_PAD_LEFT),
                    'year' => $year,
                    'sequence' => $sequence,
                    'issued_at' => $at,
                    'currency' => $payment->currency,
                    'amount' => (string) $amount->toScale($places, RoundingMode::HalfUp),
                    'net' => (string) $net->toScale($places, RoundingMode::HalfUp),
                    'tax' => $rate === null || $rate->isZero() ? null : (string) $amount->minus($net)->toScale($places, RoundingMode::HalfUp),
                    'tax_name' => $rate === null || $rate->isZero() ? null : $details['taxName'],
                    'tax_rate' => $rate === null || $rate->isZero() ? null : (string) $rate,
                    'description' => __('invoices.line', ['account' => $workspace->name, 'brand' => $this->brand->current()['name']]),
                    'seller' => $this->seller($details),
                    'buyer' => $this->buyer($workspace, $payment),
                ])->save();

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            return CustomerInvoice::query()->where('payment_id', $payment->id)->first(); // issued at the same time elsewhere
        }
    }

    /** Payments credited with no invoice yet are issued theirs. Answers how many. */
    public function catchUp(): int
    {
        $due = Payment::query()->where('status', Payment::PAID)->whereNotNull('workspace_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('customer_invoices')->whereColumn('customer_invoices.payment_id', 'payments.id'))
            ->orderBy('paid_at')->orderBy('id')->limit((int) config('kit.invoices.catch_up_per_run'))->get();
        $issued = 0;
        foreach ($due as $payment) {
            $issued += $this->issue($payment) !== null ? 1 : 0;
        }

        return $issued;
    }

    /** @return array{prefix: string, name: ?string, address: ?string, taxNumber: ?string, email: ?string, taxName: ?string, taxRate: ?string, sequenceDigits: int} the operator's */
    public function settings(): array
    {
        $saved = (array) $this->settings->get(self::SETTINGS, []);

        return [
            'prefix' => is_string($saved['prefix'] ?? null) ? $saved['prefix'] : (string) config('kit.invoices.default_prefix'),
            'name' => $saved['name'] ?? null,
            'address' => $saved['address'] ?? null,
            'taxNumber' => $saved['taxNumber'] ?? null,
            'email' => $saved['email'] ?? null,
            'taxName' => $saved['taxName'] ?? null,
            'taxRate' => $saved['taxRate'] ?? null,
            'sequenceDigits' => (int) config('kit.invoices.sequence_digits'), // how the number looks: not the operator's to set
        ];
    }

    /** Save the operator's details (Admin → Invoices). Throws a ValidationException naming the field. Answers them as saved. */
    public function saveSettings(array $input): array
    {
        $c = config('kit.invoices');
        $values = Validator::make($input, [
            'prefix' => ['present', 'nullable', 'string', 'regex:'.$c['prefix_pattern']],
            'name' => ['present', 'nullable', 'string', 'max:'.$c['name_max_length']],
            'address' => ['present', 'nullable', 'string', 'max:'.$c['address_max_length']],
            'taxNumber' => ['present', 'nullable', 'string', 'max:'.$c['tax_number_max_length']],
            'email' => ['present', 'nullable', 'string', 'email'],
            'taxName' => ['present', 'nullable', 'string', 'max:'.$c['tax_name_max_length'], 'required_with:taxRate'],
            'taxRate' => ['present', 'nullable', 'string', 'regex:/^(100|[1-9]?[0-9])(\.[0-9]{1,2})?$/', 'required_with:taxName'],
        ], ['taxRate.regex' => __('invoices.rate_shape')], __('invoices.fields'))->validate();
        $clean = array_map(fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null, $values);
        if (BigDecimal::of($clean['taxRate'] ?? '0')->isGreaterThan(100)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['taxRate' => [__('invoices.rate_shape')]]);
        }
        $clean['prefix'] = $clean['prefix'] ?? '';
        $this->settings->put(self::SETTINGS, $clean);

        return $this->settings();
    }

    /** @return array{name: ?string, address: ?string, taxNumber: ?string} the customer's billing details */
    public function billingDetails(Workspace $workspace): array
    {
        return ['name' => $workspace->billing_name, 'address' => $workspace->billing_address, 'taxNumber' => $workspace->billing_tax_number];
    }

    /** Save the customer's billing details, printed on the invoices issued from now on. Throws a ValidationException naming the field. */
    public function setBillingDetails(Workspace $workspace, array $input): array
    {
        $c = config('kit.invoices');
        $values = Validator::make($input, [
            'name' => ['present', 'nullable', 'string', 'max:'.$c['name_max_length']],
            'address' => ['present', 'nullable', 'string', 'max:'.$c['address_max_length']],
            'taxNumber' => ['present', 'nullable', 'string', 'max:'.$c['tax_number_max_length']],
        ], [], __('invoices.fields'))->validate();
        $clean = array_map(fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null, $values);
        $workspace->forceFill(['billing_name' => $clean['name'], 'billing_address' => $clean['address'], 'billing_tax_number' => $clean['taxNumber']])->save();

        return $this->billingDetails($workspace);
    }

    /** @return array{invoices: list<array>, page: int, perPage: int, total: int} an account's invoices, newest first, a page at a time */
    public function page(Workspace $workspace, mixed $page): array
    {
        $perPage = (int) config('kit.admin.per_page');
        $page = max(1, (int) (is_numeric($page) ? $page : 1));
        $query = CustomerInvoice::query()->where('workspace_id', $workspace->id);
        $rows = (clone $query)->with('payment.gateway')->orderByDesc('issued_at')->orderByDesc('year')->orderByDesc('sequence')
            ->forPage($page, $perPage)->get();

        return [
            'invoices' => $rows->map(fn (CustomerInvoice $i) => array_intersect_key($this->present($i), array_flip(['id', 'number', 'issuedAt', 'currency', 'amount', 'refunded'])))->all(),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $query->count(),
        ];
    }

    /** An invoice as the page shows it. */
    public function present(CustomerInvoice $invoice): array
    {
        $refunded = BigDecimal::of($invoice->payment?->refunded ?? '0');

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'issuedAt' => $invoice->issued_at->toIso8601String(),
            'currency' => $invoice->currency,
            'description' => $invoice->description,
            'amount' => $invoice->amount,
            'net' => $invoice->net,
            'tax' => $invoice->tax === null ? null : ['name' => $invoice->tax_name, 'rate' => $invoice->tax_rate, 'amount' => $invoice->tax],
            'seller' => $invoice->seller,
            'buyer' => $invoice->buyer,
            'paidWith' => $invoice->payment?->gateway?->name,
            'refunded' => $refunded->isPositive() ? (string) $refunded->toScale(Money::decimals($invoice->currency), RoundingMode::HalfUp) : null,
        ];
    }

    /** @return array{name: string, address: ?string, taxNumber: ?string, email: ?string} */
    private function seller(array $details): array
    {
        $brand = $this->brand->current();

        return ['name' => $details['name'] ?? $brand['name'], 'address' => $details['address'], 'taxNumber' => $details['taxNumber'], 'email' => $details['email'] ?? $brand['supportEmail']];
    }

    /** @return array{name: string, address: ?string, taxNumber: ?string, email: ?string} */
    private function buyer(Workspace $workspace, Payment $payment): array
    {
        return ['name' => $workspace->billing_name ?? $workspace->name, 'address' => $workspace->billing_address, 'taxNumber' => $workspace->billing_tax_number,
            'email' => $payment->user?->email];
    }
}
