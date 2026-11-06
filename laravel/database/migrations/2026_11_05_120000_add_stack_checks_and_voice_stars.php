<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last check of an agent's voice setup (App\Kit\Agents\StackCheck: each part's verdict in the kit's words, and when), and the voices each member starred in an account
 * (App\Kit\Voices\VoiceStars). Both the kit's own: Telenow keeps one list of stars for every key of an account (recorded), so they would not be a member's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->longText('stack_check')->nullable(); // text: MySQL's json type reorders an object's keys (AnyDatabaseTest)
            $table->timestamp('stack_checked_at')->nullable();
        });
        Schema::create('voice_stars', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('provider_id', 64);
            $table->string('voice_id', 255);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'provider_id', 'voice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_stars');
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['stack_check', 'stack_checked_at']);
        });
    }
};
