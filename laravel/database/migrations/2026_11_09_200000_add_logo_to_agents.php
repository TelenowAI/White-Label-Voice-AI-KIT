<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent's logo (App\Kit\Agents\AgentLogos): where its image is kept on the kit's own disk (config('kit.agents.logo.disk')), named by its
 * content, or null for none. Owner's choice 11-09: kept in the kit, so no voice-platform address is ever shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('logo', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }
};
