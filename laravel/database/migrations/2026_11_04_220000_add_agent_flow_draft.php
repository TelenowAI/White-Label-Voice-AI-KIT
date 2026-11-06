<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call steps being worked on, kept here and not at the voice platform, where every save would make a version and a half-made graph could be read (App\Kit\Agents\AgentFlows). Any key typed in one is sealed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->text('flow_draft')->nullable();
            $table->timestamp('flow_draft_saved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['flow_draft', 'flow_draft_saved_at']);
        });
    }
};
