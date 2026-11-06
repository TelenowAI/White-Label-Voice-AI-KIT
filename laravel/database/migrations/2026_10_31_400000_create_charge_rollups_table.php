<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each day's charges added up (App\Kit\Wallet\ChargeRollups), written with each charge: the admin dashboard and
 * Cost vs price read these, never every charge, so a report costs days × customers whatever the call volume.
 * `subject` is '' for a charge as a whole, else one priced line's subject (`call`, `component:<name>`, `fee:<kind>`).
 * Money is a decimal string, as everywhere in the kit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_rollups', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('plan')->default('');
            $table->string('subject')->default('');
            $table->string('currency', 3);
            $table->unsignedInteger('count')->default(0);
            $table->string('price')->default('0');
            $table->string('cost_usd')->default('0');
            $table->string('cost')->default('0');
            $table->timestamps();
            $table->unique(['day', 'workspace_id', 'plan', 'subject', 'currency']);
            $table->index(['subject', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_rollups');
    }
};
