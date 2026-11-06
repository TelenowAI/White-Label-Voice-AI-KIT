<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each customer's do-not-call list as the kit keeps it (App\Kit\Dnc; contract/resources.yaml `dnc`): the numbers it
 * added (sent to Telenow through the outbox) and the ones Telenow added itself (a caller's opt-out), with the call it
 * was asked on. A removed number keeps its row, marked deleted. Also: where each account's DNC feed stopped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dnc_entries', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('phone_number');
            $table->string('reason')->nullable();
            $table->string('source')->nullable();
            $table->foreignUlid('call_id', 36)->nullable()->constrained()->nullOnDelete();
            $table->char('telenow_id', 36)->nullable()->unique();
            $table->string('sync_state')->default('pending');
            $table->text('sync_error')->nullable();
            $table->string('telenow_updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'deleted_at', 'created_at']);
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('dnc_cursor')->nullable();
            $table->timestamp('dnc_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['dnc_cursor', 'dnc_synced_at']);
        });
        Schema::dropIfExists('dnc_entries');
    }
};
