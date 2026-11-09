<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Starter agents (App\Kit\Agents\StarterAgents): ready-made agents the operator offers every account, each a snapshot of an agent in an account the
 * publishing staff member owns, taken without anything of that account (no keys, tools, look-ups, hand-overs, transfers, knowledge, webhooks, email
 * recipients or own voice). `agent` is what a new agent is made with, `flow` its call steps; the source's names are kept as they were when published,
 * for the operator's page. Withdrawn ones are kept, no longer offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starter_agents', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('agent');
            $table->longText('flow')->nullable();
            $table->char('source_agent_id', 36)->nullable();
            $table->string('source_agent_name')->nullable();
            $table->string('source_account_name')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['withdrawn_at', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starter_agents');
    }
};
