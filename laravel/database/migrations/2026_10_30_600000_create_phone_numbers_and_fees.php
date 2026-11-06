<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each customer's phone numbers as the kit keeps them (App\Kit\Numbers; contract/resources.yaml `numbers`), and the
 * number fees Telenow charged for them (`telenow_fees`, from the customer's ledger: rental, number_setup), each charged
 * to the wallet once (App\Kit\Wallet\FeeCharges). Also where each account's numbers feed and ledger feed stopped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('agent_id', 36)->nullable()->constrained()->nullOnDelete();
            $table->string('e164');
            $table->string('country')->nullable();
            $table->string('region')->nullable();
            $table->string('number_type')->nullable();
            $table->string('monthly_price')->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->char('telenow_id', 36)->nullable()->unique();
            $table->string('sync_state')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('next_renewal_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('telenow_updated_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'released_at']);
        });
        Schema::create('telenow_fees', function (Blueprint $table) {
            $table->char('id', 36)->primary(); // Telenow's ledger entry
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('ref_kind');
            $table->string('ref_id')->nullable();
            $table->string('amount_usd');
            $table->longText('entry');
            $table->string('charge_state');
            $table->text('charge_error')->nullable();
            $table->timestamps();
            $table->index(['charge_state']);
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('numbers_after')->nullable();
            $table->string('ledger_cursor')->nullable();
            $table->timestamp('numbers_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['numbers_after', 'ledger_cursor', 'numbers_synced_at']);
        });
        Schema::dropIfExists('telenow_fees');
        Schema::dropIfExists('phone_numbers');
    }
};
