<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K1 slice 7a (SELF_HOSTED_KIT_PLAN.md §4.7 Email): the kit's outgoing emails and password reset links.
 *
 * - `emails`: one row per email the kit sends — kept so one every sender failed is tried again (kit:emails) and the
 *   operator sees what went out. The body is encrypted (it may hold a link's secret) and wiped once the email is sent
 *   or given up.
 * - `password_resets`: a reset link's token is kept only as its hash (like an invite's); a link works once, until it
 *   expires. Laravel's own `password_reset_tokens` (keyed by email) is not used: the kit's link carries no address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('kind');
            $table->string('to_address');
            $table->string('subject');
            $table->text('body')->nullable();
            $table->string('status'); // queued | sent | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->string('sender_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
            $table->index('created_at');
        });

        Schema::create('password_resets', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('emails');
    }
};
