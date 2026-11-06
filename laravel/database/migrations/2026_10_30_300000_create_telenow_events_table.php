<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The events inbox (App\Kit\Events\Inbox; SELF_HOSTED_KIT_PLAN.md §3.8 c, e): each delivery Telenow sent, stored raw
 * the moment it arrives and applied by a job afterwards. `delivery_id` is Telenow's X-VoiceAI-Delivery — unique, so a
 * delivery sent twice is stored once (the signature has no timestamp: this is also the replay protection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telenow_events', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('event');
            $table->longText('body');
            $table->char('sub_account_id', 36)->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->useCurrent();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['applied_at', 'failed_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telenow_events');
    }
};
