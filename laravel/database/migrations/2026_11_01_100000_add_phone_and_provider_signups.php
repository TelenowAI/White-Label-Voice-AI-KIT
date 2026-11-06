<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing up with a phone or a sign-in provider (App\Kit\Signup\Signups; owner 11-01):
 * - `users.email` may be empty: a customer who signed up with their phone has none, and signs in with a code texted to
 *   their proven `users.phone`. It stays unique.
 * - `signups.email` may be empty for the same reason; such a sign-up has a `phone` instead. Its code is kept only as a
 *   hash in `token_hash` (the secret that confirms a sign-up, a link's or a code's) and `code_attempts` counts its tries.
 * - `provider_id` and `provider_subject`: a sign-up made with a sign-in provider (its id in the kit, the person's `sub`
 *   there), remembered as the person's identity when the account is made.
 *
 * Down puts NOT NULL back, which fails while a row has no email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::table('signups', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable();
            $table->unsignedInteger('code_attempts')->default(0);
            $table->string('provider_id')->nullable();
            $table->string('provider_subject')->nullable();
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('signups', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropColumn(['phone', 'code_attempts', 'provider_id', 'provider_subject']);
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
