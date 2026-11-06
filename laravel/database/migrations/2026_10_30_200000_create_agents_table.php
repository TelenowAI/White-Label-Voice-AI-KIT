<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `agents`: a customer's agents, written by the kit first and sent to Telenow through the outbox
 * (SELF_HOSTED_KIT_PLAN.md §3.5, §3.8 a; contract/resources.yaml `agents`). The columns Telenow also keeps are the
 * manifest's fields; the provider stack is null until Telenow has made the agent (the kit asks it for a stack then).
 * A deleted agent keeps its row (deleted_at) once Telenow has deleted it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('system_prompt')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('llm_provider')->nullable();
            $table->string('llm_model')->nullable();
            $table->string('stt_provider')->nullable();
            $table->string('tts_provider')->nullable();
            $table->string('tts_voice')->nullable();
            $table->char('telenow_id', 36)->nullable()->unique();
            $table->unsignedInteger('telenow_version')->nullable();
            $table->string('sync_state')->default('pending')->index();
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->longText('telenow_snapshot')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'deleted_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
