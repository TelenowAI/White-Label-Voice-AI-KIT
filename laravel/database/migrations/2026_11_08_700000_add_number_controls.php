<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Number controls (App\Kit\Numbers): whether a number is its agent's caller id when a call names none (Telenow's `is_default_outbound`); what a
 * call to it does while no agent answers it, as Telenow keeps it (`inbound_action`: speak, reject, no-answer, forward; null for the account's
 * default, a short message); its monthly rent in USD as Telenow charges the operator (`monthly_rent_usd`, priced on the customer's plan for the
 * renewal warnings, never shown); which renewal the warning email went out for; and, for a number Telenow gave up when its rent was not paid,
 * when the customer asked for it back and the number it came back as.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->boolean('agent_caller_id')->default(false);
            $table->string('inbound_action', 16)->nullable();
            $table->string('rent_usd', 32)->nullable();
            $table->timestamp('renewal_warned_for')->nullable();
            $table->timestamp('get_back_asked_at')->nullable();
            $table->char('got_back_as', 36)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['agent_caller_id', 'inbound_action', 'rent_usd', 'renewal_warned_for', 'get_back_asked_at', 'got_back_as']);
        });
    }
};
