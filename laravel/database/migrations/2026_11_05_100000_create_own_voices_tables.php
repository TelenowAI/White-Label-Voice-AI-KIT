<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account's own voices (App\Kit\Voices\OwnVoices): each one a person who agreed to have their voice cloned, as Telenow's voice profile, with who in the kit confirmed the release and
 * when (kept after the voice is deleted, as the record of that confirmation), and the providers it is cloned into. The sample itself is never kept here, nor a provider's voice id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('own_voices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->string('telenow_id', 64)->nullable()->unique();
            $table->string('name');
            $table->string('person_name');
            $table->string('relation', 32);
            $table->string('language', 16)->nullable();
            $table->text('script')->nullable();
            $table->string('source', 16);
            $table->string('release_version', 64);
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at');
            $table->decimal('sample_seconds', 8, 2)->nullable();
            $table->string('sample_type', 100)->nullable();
            $table->timestamp('sample_added_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'deleted_at']);
        });
        Schema::create('own_voice_clones', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('own_voice_id', 36);
            $table->foreign('own_voice_id')->references('id')->on('own_voices')->cascadeOnDelete();
            $table->string('provider_id', 64);
            $table->string('telenow_id', 64)->nullable();
            $table->string('status', 16);
            $table->unsignedBigInteger('cloned_by')->nullable();
            $table->timestamps();
            $table->unique(['own_voice_id', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('own_voice_clones');
        Schema::dropIfExists('own_voices');
    }
};
