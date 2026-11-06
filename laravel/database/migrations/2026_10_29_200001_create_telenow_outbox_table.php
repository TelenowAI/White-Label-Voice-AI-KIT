<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `telenow_outbox`: writes waiting to reach Telenow (SELF_HOSTED_KIT_PLAN.md §3.5, §3.8 a). Written in the same
 * transaction as the kit row it changes; sent at once for an interactive action and retried by `kit:outbox` with the
 * same Idempotency-Key until it is done or failed. One row's writes go in order. Read and written only by
 * App\Kit\Outbox\Outbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telenow_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('resource');
            $table->string('kit_id');
            $table->string('operation');
            $table->longText('body')->nullable();
            $table->string('idempotency_key');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->useCurrent()->index();
            // A runner holds a write while it sends it; another runner leaves it alone until then.
            $table->timestamp('locked_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['resource', 'kit_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telenow_outbox');
    }
};
