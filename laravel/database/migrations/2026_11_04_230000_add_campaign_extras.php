<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a campaign waits before it tries someone again (a campaign made before this has the voice platform's own default), a person being called again after a retry, and looking people up by how their call went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedInteger('retry_backoff_secs')->default(300);
        });
        Schema::table('campaign_targets', function (Blueprint $table) {
            $table->timestamp('retried_at')->nullable();
            $table->index(['campaign_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::table('campaign_targets', function (Blueprint $table) {
            $table->dropIndex(['campaign_id', 'outcome']);
            $table->dropColumn('retried_at');
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('retry_backoff_secs');
        });
    }
};
