<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The web addresses an agent's finished calls are sent to (App\Kit\Agents\AgentWebhooks), and each one's secret until it has been shown. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_webhooks', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('agent_id', 36)->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->text('events');
            $table->boolean('include_transcript')->default(false);
            $table->char('telenow_id', 36)->nullable()->unique();
            $table->string('sync_state')->default('pending');
            $table->text('sync_error')->nullable();
            $table->text('signing_secret')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_webhooks');
    }
};
