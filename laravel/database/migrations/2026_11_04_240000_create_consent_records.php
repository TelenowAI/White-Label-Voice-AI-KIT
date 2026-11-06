<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's proof that people agreed to be called, and whether their calls need it. A record is never changed or removed, only withdrawn: it is what they show after a complaint. The voice platform has no list of them, only one number's at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('phone_number');
            $table->string('consent_type');
            $table->string('scope');
            $table->string('source');
            $table->text('disclosure_text')->nullable();
            $table->string('disclosure_version')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_ip')->nullable();
            $table->text('recorded_agent')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_note')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoke_sent_at')->nullable();
            $table->string('telenow_id')->nullable()->unique();
            $table->timestamp('tried_at')->nullable();
            $table->string('sync_state')->default('pending');
            $table->text('sync_error')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'phone_number']);
            $table->index(['workspace_id', 'sync_state']);
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('consent_required')->default(false);
            $table->boolean('consent_locked')->default(false);
            $table->string('consent_mode')->default('off');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['consent_required', 'consent_locked', 'consent_mode']);
        });
        Schema::dropIfExists('consent_records');
    }
};
