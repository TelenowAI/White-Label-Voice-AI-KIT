<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Staff (App\Kit\Staff): invites to the operator's team (`staff_invites`, the link's token kept only as its
 * sha256), and the audit log (`audit_events`, App\Kit\Audit): who did what to whom in the admin panel, kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invites', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash')->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->longText('details')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('staff_invites');
    }
};
