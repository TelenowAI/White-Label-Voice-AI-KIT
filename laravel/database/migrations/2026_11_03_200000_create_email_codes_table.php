<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing in with a code emailed each time (App\Kit\Auth\EmailCodes): each one-time code, only its hash, with its tries and
 * its expiry. A sign-up's own code is kept on the sign-up, as a phone sign-up's is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('code_hash');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_codes');
    }
};
