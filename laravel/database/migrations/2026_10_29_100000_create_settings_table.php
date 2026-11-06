<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `settings`: the operator's configuration, one row per key (SELF_HOSTED_KIT_PLAN.md §3.5). The value is JSON;
 * a secret's value (a Telenow key, a gateway secret) is encrypted with the install's APP_KEY. Read and written
 * only through App\Kit\Settings\Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value');
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
