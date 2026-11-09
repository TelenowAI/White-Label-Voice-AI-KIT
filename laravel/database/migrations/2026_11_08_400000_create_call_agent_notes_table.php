<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a manager told the agent during a live call (App\Kit\Calls\AgentNotes): the words, whether the agent was asked to say it at once, and when the
 * voice platform said the agent would see it. Kept so the call's page shows who told the agent what; never sent anywhere again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_agent_notes', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('call_id', 36)->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('text');
            $table->boolean('say_now')->default(false);
            $table->string('delivery', 16);
            $table->timestamps();
            $table->index(['call_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_agent_notes');
    }
};
