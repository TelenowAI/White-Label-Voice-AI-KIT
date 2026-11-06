<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's balance emails (App\Kit\Wallet\BalanceAlerts): the level the account chose to be warned at (an exact
 * decimal string in the platform's currency; null: the operator's default), and when each email last went — the
 * low-balance one and the out-of-credit one. Each is sent once when the balance falls to its level and is armed again
 * (set back to null) once the balance is above it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('low_balance_at')->nullable();
            $table->timestamp('low_balance_warned_at')->nullable();
            $table->timestamp('out_of_credit_warned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['low_balance_at', 'low_balance_warned_at', 'out_of_credit_warned_at']);
        });
    }
};
