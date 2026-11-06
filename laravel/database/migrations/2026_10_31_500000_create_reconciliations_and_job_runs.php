<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Voice platform's health (App\Kit\Health):
 * - `reconciliations`: each customer's last money check (App\Kit\Health\Reconciliation, `kit:reconcile`) — what
 *   Telenow says the sub-account spent in all, what the kit charged for, the gap, and the calls each counts;
 * - `job_runs`: when each scheduled job last started and finished (App\Kit\Health\JobRuns), so the admin can see that
 *   the one cron line runs;
 * - indexes for the health page's counts on the events inbox and the outbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->foreignUlid('workspace_id', 36)->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at')->nullable()->index();
            $table->string('telenow_spend_usd')->nullable();
            $table->string('charged_cost_usd')->nullable();
            $table->string('drift_usd')->nullable();
            $table->timestamp('period_from')->nullable();
            $table->unsignedInteger('telenow_period_calls')->nullable();
            $table->unsignedInteger('kit_period_calls')->nullable();
            $table->unsignedInteger('uncharged_calls')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('job_runs', function (Blueprint $table) {
            $table->string('command')->primary();
            $table->string('expression');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('succeeded')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->timestamps();
        });

        Schema::table('telenow_events', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('failed_at');
        });
        Schema::table('telenow_outbox', function (Blueprint $table) {
            $table->index('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('telenow_outbox', fn (Blueprint $table) => $table->dropIndex(['failed_at']));
        Schema::table('telenow_events', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['failed_at']);
        });
        Schema::dropIfExists('job_runs');
        Schema::dropIfExists('reconciliations');
    }
};
