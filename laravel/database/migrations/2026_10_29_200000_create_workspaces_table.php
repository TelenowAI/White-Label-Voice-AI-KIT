<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `workspaces`: one per customer account, each a Telenow sub-account (SELF_HOSTED_KIT_PLAN.md §3.5, §5 C2). The kit's
 * row is written first; `sync_state` says whether Telenow has it yet (§3.8 a). The customer's own Telenow key is
 * stored encrypted with the install's APP_KEY and never leaves the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name');
            // What the operator set: active | suspended (closed comes with K1's close flow).
            $table->string('status')->default('active');
            $table->char('telenow_sub_account_id', 36)->nullable()->unique();
            $table->text('telenow_key')->nullable();
            $table->string('telenow_key_last_four')->nullable();
            // pending | synced | failed, and Telenow's reason when it refused.
            $table->string('sync_state')->default('pending')->index();
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            // The last sub-account object Telenow returned.
            $table->longText('telenow_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
