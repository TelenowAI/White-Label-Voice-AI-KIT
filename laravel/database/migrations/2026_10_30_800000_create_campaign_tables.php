<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's campaigns (App\Kit\Campaigns; contract/resources.yaml `campaigns`, `campaign_targets`): each campaign with
 * its settings, Telenow's id, status and counts, why it is not calling right now (`dial_block`), what pushing its call
 * list met (`push_summary`) and where the results feed was read to; each person to call with the kit's own id (which
 * travels to Telenow and back on the result), when it was sent, and how the call went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('agent_id', 36)->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('phone_number_id', 36)->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('concurrency');
            $table->string('start_time_local')->nullable();
            $table->string('end_time_local')->nullable();
            $table->string('timezone');
            $table->string('machine_detection');
            $table->unsignedSmallInteger('max_attempts');
            $table->boolean('retry_on_no_answer');
            $table->string('status')->nullable();
            $table->unsignedInteger('total_targets')->default(0);
            $table->unsignedInteger('completed_targets')->default(0);
            $table->unsignedInteger('failed_targets')->default(0);
            $table->longText('dial_block')->nullable();
            $table->longText('push_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('telenow_id')->nullable()->unique();
            $table->string('telenow_updated_at')->nullable();
            $table->string('results_cursor')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('sync_state')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'deleted_at']);
        });
        Schema::create('campaign_targets', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('campaign_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('phone_number');
            $table->longText('variables')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->string('outcome')->nullable();
            $table->unsignedSmallInteger('attempt')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('telenow_call_id')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'pushed_at']);
            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_targets');
        Schema::dropIfExists('campaigns');
    }
};
