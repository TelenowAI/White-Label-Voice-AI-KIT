<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → a customer (App\Kit\Customers\Customers): the limits the operator sets at Telenow — most concurrent calls,
 * a monthly cap on Telenow's charges in USD (null: none) — and closing: when it was asked for and when Telenow closed
 * the sub-account (status `closing` then `closed`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('max_concurrent_calls')->nullable();
            $table->string('monthly_cap_usd')->nullable();
            $table->timestamp('close_requested_at')->nullable();
            $table->timestamp('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['max_concurrent_calls', 'monthly_cap_usd', 'close_requested_at', 'closed_at']);
        });
    }
};
