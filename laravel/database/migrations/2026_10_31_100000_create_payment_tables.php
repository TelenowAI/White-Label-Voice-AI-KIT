<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (App\Kit\Payments; SELF_HOSTED_KIT_PLAN.md §4.6 a):
 * - `payment_gateways`: the ways to pay the operator switched on, each a driver from config('kit.payments.drivers')
 *   with its own config (keys, stored encrypted), its rules (payment methods shown, plans offered to) and its place in
 *   the order. A removed one is kept (`removed_at`) so its late refunds and disputes still arrive.
 * - `payments`: each top-up a customer started (or a staff member's test payment, with no customer), its amount as an
 *   exact decimal string, its state, the gateway's own id for it, and what came back: the amount received, refunded,
 *   and any dispute (`details` keeps each refund and dispute by the gateway's id, so each counts once).
 * - `payment_events`: each verified event a gateway sent, its body encrypted (it can name the payer), kept
 *   config('kit.payments.events_keep_days').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('driver');
            $table->string('name');
            $table->string('mode');
            $table->text('config');
            $table->longText('methods');
            $table->longText('plans')->nullable();
            $table->unsignedInteger('position');
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('gateway_id', 36)->constrained('payment_gateways');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('gateway_ref')->nullable();
            $table->string('received')->nullable();
            $table->string('refunded')->default('0');
            $table->string('dispute')->nullable();
            $table->longText('details')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['gateway_id', 'gateway_ref']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('gateway_id', 36)->constrained('payment_gateways');
            $table->foreignUlid('payment_id', 36)->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('event_ref')->nullable();
            $table->longText('payload');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_gateways');
    }
};
