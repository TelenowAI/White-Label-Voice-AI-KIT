<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each account's calls feed stopped (App\Kit\Calls\Calls::feed): Telenow's cursor, and when it was last read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('calls_cursor')->nullable();
            $table->timestamp('calls_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['calls_cursor', 'calls_synced_at']);
        });
    }
};
