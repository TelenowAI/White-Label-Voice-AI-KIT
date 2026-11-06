<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each customer's wallet (App\Kit\Wallet\Wallet; SELF_HOSTED_KIT_PLAN.md §4.6 b): one row per credit or charge, the
 * amount signed (a charge is negative) as an exact decimal string, and the balance after it. `reference` makes each
 * entry happen once: a credit's own id, a charge's call id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('reference');
            $table->string('amount');
            $table->string('currency', 3);
            $table->string('balance_after');
            $table->json('details')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'kind', 'reference']);
            $table->index(['workspace_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
    }
};
