<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked an agent to call someone (App\Kit\Agents\AgentCalls): kept beside the call, as `placed_by` keeps who called from the browser. The agent is on the line; this member asked for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->index(['workspace_id', 'requested_by', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'requested_by', 'started_at']);
            $table->dropColumn('requested_by');
        });
    }
};
