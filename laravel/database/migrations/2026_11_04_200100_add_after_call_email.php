<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who hears about an agent's finished calls by email, and which calls have been told (App\Kit\Agents\AfterCallEmail). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->longText('after_call_email')->nullable()->after('settings');
        });
        Schema::table('calls', function (Blueprint $table) {
            $table->timestamp('after_call_emailed_at')->nullable()->after('next_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('after_call_email');
        });
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('after_call_emailed_at');
        });
    }
};
