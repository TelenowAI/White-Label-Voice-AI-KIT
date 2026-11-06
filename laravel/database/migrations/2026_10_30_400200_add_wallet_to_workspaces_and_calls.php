<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's balance (kept with each wallet entry), its plan (a price list; none = the default plan), and the spend
 * limit last set at Telenow; and how far each call's charge got (App\Kit\Wallet\CallCharges).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('balance')->default('0');
            $table->string('price_list_id')->nullable();
            $table->string('spend_limit_usd')->nullable();
            $table->timestamp('spend_limit_at')->nullable();
        });
        Schema::table('calls', function (Blueprint $table) {
            $table->string('charge_state')->nullable();
            $table->unsignedInteger('charge_reads')->default(0);
            $table->timestamp('charge_next_at')->nullable();
            $table->text('charge_error')->nullable();
            $table->string('charge_amount')->nullable();
            $table->string('charge_currency', 3)->nullable();
            $table->index(['charge_state', 'charge_next_at']);
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['charge_state', 'charge_next_at']);
            $table->dropColumn(['charge_state', 'charge_reads', 'charge_next_at', 'charge_error', 'charge_amount', 'charge_currency']);
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['balance', 'price_list_id', 'spend_limit_usd', 'spend_limit_at']);
        });
    }
};
