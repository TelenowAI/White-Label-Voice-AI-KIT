<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's knowledge (App\Kit\Knowledge; contract/resources.yaml `knowledge_bases`, `knowledge_documents`): its
 * bases and their documents, each with Telenow's id once made there; a document's Telenow status (still being processed,
 * ready, failed) comes back through the base's change feed, read from `documents_after`. Which bases an agent answers
 * from: `agent_knowledge_base`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('telenow_id')->nullable()->unique();
            $table->string('telenow_updated_at')->nullable();
            $table->string('documents_after')->nullable();
            $table->timestamp('documents_read_at')->nullable();
            $table->string('sync_state')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'deleted_at']);
        });
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('knowledge_base_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('source_type')->nullable();
            $table->string('source_uri', 2048)->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('char_count')->nullable();
            $table->string('telenow_id')->nullable()->unique();
            $table->string('telenow_updated_at')->nullable();
            $table->string('sync_state')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['knowledge_base_id', 'deleted_at']);
        });
        Schema::create('agent_knowledge_base', function (Blueprint $table) {
            $table->foreignUlid('agent_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('knowledge_base_id', 36)->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['agent_id', 'knowledge_base_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_knowledge_base');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('knowledge_bases');
    }
};
