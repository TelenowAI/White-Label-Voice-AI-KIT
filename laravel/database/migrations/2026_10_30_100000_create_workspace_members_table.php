<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `workspace_members`: who belongs to a customer account, and as what (config('kit.team.roles')). A customer's users
 * are ordinary `users` rows with no staff role; their rights come from here (SELF_HOSTED_KIT_PLAN.md §3.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
