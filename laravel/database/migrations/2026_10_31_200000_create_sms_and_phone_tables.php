<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text messages and phone numbers (App\Kit\Sms, App\Kit\Auth\PhoneCodes; SELF_HOSTED_KIT_PLAN.md §4.5, §4.7):
 * - `users.phone`: a person's number in E.164, set only once they proved it with a code (`phone_verified_at`); one
 *   person per number;
 * - `sms_messages`: each text the kit sent or tried — never its words, which can hold a code — for Admin → SMS;
 * - `phone_codes`: each one-time code, only its hash, with what it is for (signing in, or proving a new number), its
 *   tries and its expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('kind');
            $table->string('to_number', 20);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('phone_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->string('phone', 20);
            $table->string('code_hash');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_codes');
        Schema::dropIfExists('sms_messages');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at']);
        });
    }
};
