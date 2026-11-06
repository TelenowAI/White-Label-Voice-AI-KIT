<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who listened in to a call, and when (App\Kit\Calls\ListenIn): one row each time a manager starts listening. The kit's own, shown on the call's page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_listens', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('call_id', 36)->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->index(['call_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_listens');
    }
};
