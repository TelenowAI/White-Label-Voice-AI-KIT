<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign's people from a link (App\Kit\Campaigns\CampaignSources): the link, when it was last read, why it could not be (in the customer's words),
 * and how many people it has added. Nothing else of the file is kept: the people are the campaign's own targets.
 *
 * The index is on when a link was last read alone: a link is read when it is set, so only a followed link has that time, and the runs ask for the
 * oldest ones past due. Never on the link itself: MySQL and MariaDB refuse a key past 3,072 bytes, and 2,048 characters of utf8mb4 are 8,192.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('source_url', 2048)->nullable();
            $table->timestamp('source_checked_at')->nullable();
            $table->string('source_problem')->nullable();
            $table->unsignedInteger('source_added')->default(0);
            $table->index('source_checked_at', 'campaigns_source_due');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_source_due');
            $table->dropColumn(['source_url', 'source_checked_at', 'source_problem', 'source_added']);
        });
    }
};
