<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web chat (App\Kit\Chat): an agent's chat box for the customer's own website (one per agent, found by its public key), and each conversation a
 * visitor has in one. A conversation's token is kept only as its hash; the website it started from is kept, as every later message must come from it.
 * `ended_at` is when the kit stopped taking messages; `telenow_ended_at` when Telenow confirmed the session ended (kit:chats tries until it does).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_web_chats', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('agent_id', 36)->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('public_key', 64)->nullable()->unique();
            $table->boolean('enabled')->default(false);
            $table->longText('websites');
            $table->text('title')->nullable();
            $table->text('greeting')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('web_chat_id', 36)->constrained('agent_web_chats')->cascadeOnDelete();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('website');
            $table->char('telenow_id', 36)->nullable()->unique();
            $table->foreignUlid('call_id', 36)->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('messages')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->timestamp('telenow_ended_at')->nullable();
            $table->timestamps();
            $table->index(['ended_at', 'last_message_at']);
            $table->index(['telenow_ended_at', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('agent_web_chats');
    }
};
