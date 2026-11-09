<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who takes a call to a routed number (App\Kit\Routing): an account's rules, in order, each with what it matches (the number called, the line that
 * forwarded the call, how the caller's number starts, days and hours in a timezone; a list empty for "any") and what it does (an agent answers, with
 * a language, a greeting and a cap on the call's length if set, or the call is refused); the account's endpoint at Telenow (the token in its address,
 * the secret Telenow signs with, encrypted, and the address registered); and which numbers are routed (Telenow's `is_pool`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->longText('numbers');
            $table->longText('forwarded_from');
            $table->longText('caller_starts');
            $table->longText('days');
            $table->string('from_time', 5)->nullable();
            $table->string('until_time', 5)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('action', 16);
            $table->char('agent_id', 36)->nullable();
            $table->string('language', 32)->nullable();
            $table->text('greeting')->nullable();
            $table->unsignedSmallInteger('max_minutes')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'position']);
        });
        Schema::create('routing_endpoints', function (Blueprint $table) {
            $table->foreignUlid('workspace_id', 36)->primary()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->text('secret')->nullable();
            $table->string('url', 2048)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->boolean('routed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn('routed');
        });
        Schema::dropIfExists('routing_endpoints');
        Schema::dropIfExists('routing_rules');
    }
};
