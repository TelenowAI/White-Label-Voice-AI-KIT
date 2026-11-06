<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K1 slice 7b (SELF_HOSTED_KIT_PLAN.md §4.5): sign-ups and sign-in links.
 *
 * - `signups`: a sign-up is held here, not as a user, until its address is verified (and, in approval mode, staff
 *   approve it): only then are the user, the customer account and its Telenow sub-account made, so an abandoned form
 *   never becomes a Telenow org. Its verification link's token is kept only as its hash. `status`: unverified →
 *   waiting (for approval) → done, or rejected. The password (hashed) moves to the user, and is wiped when refused.
 * - `sign_in_links`: a link emailed to sign a customer in (the magic-link method); only its token's hash is kept, and it
 *   works once, until it expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signups', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('email');
            $table->string('name');
            $table->string('account_name');
            $table->string('password')->nullable();
            $table->longText('answers')->nullable();
            $table->string('terms_version')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('ip')->nullable();
            $table->string('token_hash')->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->string('status');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('workspace_id', 36)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('email');
        });

        Schema::create('sign_in_links', function (Blueprint $table) {
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
        Schema::dropIfExists('sign_in_links');
        Schema::dropIfExists('signups');
    }
};
