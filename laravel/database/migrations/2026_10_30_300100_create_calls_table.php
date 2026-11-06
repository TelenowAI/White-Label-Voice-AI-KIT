<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kit's copy of each customer's calls (App\Kit\Calls; SELF_HOSTED_KIT_PLAN.md §3.8 b-d): the console reads only
 * this. Telenow writes them (contract/resources.yaml `calls`); `detail_state` says whether the kit still re-reads
 * the call (`pending`), has all Telenow will give (`final`), or stopped trying (`gave_up`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('agent_id', 36)->nullable()->constrained()->nullOnDelete();
            $table->char('telenow_id', 36)->unique();
            $table->string('status')->nullable();
            $table->string('outcome')->nullable();
            $table->string('direction')->nullable();
            $table->string('channel')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_sec')->nullable();
            $table->string('answered_by')->nullable();
            $table->longText('transcript')->nullable();
            $table->longText('recordings')->nullable();
            $table->longText('analysis')->nullable();
            $table->longText('completeness')->nullable();
            // Telenow's `updated_at` exactly as it sent it (microseconds and all): only ever compared, to tell a call the
            // feed lists again unchanged from one that changed.
            $table->string('telenow_updated_at')->nullable();
            $table->string('detail_state')->default('pending');
            $table->unsignedInteger('reads')->default(0);
            $table->timestamp('next_read_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'started_at']);
            $table->index(['detail_state', 'next_read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
