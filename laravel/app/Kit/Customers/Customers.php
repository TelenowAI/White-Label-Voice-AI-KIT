<?php

namespace App\Kit\Customers;

use App\Kit\Customers\Telenow\CloseAtTelenow;
use App\Kit\Customers\Telenow\SetLimitsAtTelenow;
use App\Kit\Numbers\Numbers;
use App\Kit\Outbox\Outbox;
use App\Kit\Wallet\SpendLimit;
use App\Models\PhoneNumber;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The operator's customers (admin → Customers): each a workspace in the kit's DB and a sub-account at Telenow
 * (SELF_HOSTED_KIT_PLAN.md §3.8 a, §5 C2, C6). Every change is written to the kit's row and the outbox in one
 * transaction, then sent to Telenow at once; when Telenow cannot take it yet, the row says so and `kit:outbox` keeps
 * trying.
 *
 * - Limits: the most concurrent calls and a monthly cap on Telenow's charges (USD), sent to the sub-account.
 * - Closing: suspended at once, its numbers released, then the sub-account closed for good once they are gone and its last
 *   calls are charged (Settling; CloseAtTelenow waits for that, since closing revokes the key that reads them). A closing
 *   or closed customer cannot be suspended, resumed or limited; its wallet stays as it is, for the operator to settle by
 *   hand.
 *
 * Not here: sending (Telenow/*), the HTTP answers (CustomersController), plans (App\Kit\Pricing).
 */
final class Customers
{
    public const RESOURCE = 'workspace';

    public function __construct(private readonly Outbox $outbox, private readonly Numbers $numbers, private readonly SpendLimit $spendLimit) {}

    public function create(string $name): Workspace
    {
        $workspace = DB::transaction(function () use ($name) {
            $workspace = Workspace::create(['name' => $name, 'status' => Workspace::ACTIVE, 'sync_state' => Workspace::SYNC_PENDING]);
            // The workspace id is the sub-account's externalId and the create's Idempotency-Key (§5 C2).
            $this->outbox->enqueue(self::RESOURCE, $workspace->id, 'create', [
                'name' => $name,
                'externalId' => $workspace->id,
                'keyName' => config('kit.customers.key_name'),
                'keyRole' => config('kit.customers.key_role'),
            ], $workspace->id);

            return $workspace;
        });
        $this->outbox->runFor(self::RESOURCE, $workspace->id);

        return $workspace->fresh();
    }

    public function suspend(Workspace $workspace, ?string $reason): Workspace
    {
        return $this->change($workspace, Workspace::SUSPENDED, 'suspend', ['reason' => $reason]);
    }

    public function resume(Workspace $workspace): Workspace
    {
        return $this->change($workspace, Workspace::ACTIVE, 'resume', null);
    }

    /** Send the customer's given-up writes to Telenow again. */
    public function retry(Workspace $workspace): Workspace
    {
        if ($workspace->sync_state !== Workspace::SYNC_FAILED) {
            throw new CustomerRefused(409, __('customers.nothing_to_retry'));
        }
        $workspace->forceFill(['sync_state' => Workspace::SYNC_PENDING])->save();
        $this->outbox->retry(self::RESOURCE, $workspace->id);

        return $workspace->fresh();
    }

    /** Set the customer's limits at Telenow: `maxConcurrentCalls` and `monthlyCapUsd`, each null for none. */
    public function setLimits(Workspace $workspace, array $input): Workspace
    {
        $this->refuseEnded($workspace);
        $values = Validator::make($input, [
            'maxConcurrentCalls' => ['present', 'nullable', 'integer', 'min:0'],
            'monthlyCapUsd' => ['present', 'nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ], [], ['maxConcurrentCalls' => __('customers.fields.max_concurrent_calls'), 'monthlyCapUsd' => __('customers.fields.monthly_cap_usd')])->validate();
        DB::transaction(function () use ($workspace, $values) {
            $workspace->forceFill(['max_concurrent_calls' => $values['maxConcurrentCalls'], 'monthly_cap_usd' => $values['monthlyCapUsd'],
                'sync_state' => Workspace::SYNC_PENDING])->save();
            $this->outbox->enqueue(self::RESOURCE, $workspace->id, SetLimitsAtTelenow::OPERATION, null);
        });
        $this->outbox->runFor(self::RESOURCE, $workspace->id);
        // The wallet's spend limit leaves room for each call the customer may have going at once.
        $this->spendLimit->request($workspace->fresh());

        return $workspace->fresh();
    }

    /** Close the customer for good: suspended now, numbers released, then the sub-account closed at Telenow. */
    public function close(Workspace $workspace): Workspace
    {
        $this->refuseEnded($workspace);
        if ($workspace->telenow_sub_account_id === null) {
            // Never made at Telenow: there is nothing to release or close there, and its waiting writes are dropped.
            // A create already on its way that lands anyway is closed as soon as it does (CreateAtTelenow).
            DB::transaction(function () use ($workspace) {
                $this->outbox->cancel(self::RESOURCE, $workspace->id, __('customers.closed_before_made'));
                $workspace->forceFill(['status' => Workspace::CLOSED, 'close_requested_at' => now(), 'closed_at' => now(),
                    'sync_state' => Workspace::SYNC_SYNCED, 'sync_error' => null])->save();
            });

            return $workspace->fresh();
        }
        $numbers = PhoneNumber::query()->where('workspace_id', $workspace->id)->whereNull('released_at')->get();
        DB::transaction(function () use ($workspace) {
            $wasActive = $workspace->status === Workspace::ACTIVE;
            $workspace->forceFill(['status' => Workspace::CLOSING, 'close_requested_at' => now(), 'sync_state' => Workspace::SYNC_PENDING])->save();
            if ($wasActive && $workspace->telenow_sub_account_id !== null) {
                $this->outbox->enqueue(self::RESOURCE, $workspace->id, 'suspend', ['reason' => __('customers.closing_reason')]);
            }
            $this->outbox->enqueue(self::RESOURCE, $workspace->id, CloseAtTelenow::OPERATION, null);
        });
        foreach ($numbers as $number) {
            $this->numbers->release($number);
        }
        $this->outbox->runFor(self::RESOURCE, $workspace->id);

        return $workspace->fresh();
    }

    private function refuseEnded(Workspace $workspace): void
    {
        if (in_array($workspace->status, Workspace::ENDED, true)) {
            throw new CustomerRefused(409, __("customers.already_{$workspace->status}"));
        }
    }

    private function change(Workspace $workspace, string $status, string $operation, ?array $body): Workspace
    {
        $this->refuseEnded($workspace);
        if ($workspace->status === $status) {
            throw new CustomerRefused(409, __("customers.already_{$status}"));
        }
        DB::transaction(function () use ($workspace, $status, $operation, $body) {
            $workspace->forceFill(['status' => $status, 'sync_state' => Workspace::SYNC_PENDING])->save();
            $this->outbox->enqueue(self::RESOURCE, $workspace->id, $operation, $body);
        });
        $this->outbox->runFor(self::RESOURCE, $workspace->id);

        return $workspace->fresh();
    }
}
