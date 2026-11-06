<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The voices customers may hear and pick: each voice provider's whole list as Telenow gives it (App\Kit\Catalog\CatalogVoices).
 * The lists run to thousands of voices, so they live in rows, narrowed and paged by the database, and not in the catalog's
 * one setting. Keyed by Telenow's provider and voice ids.
 * - `position`: its place in its provider's list (Telenow's order, recommended voices first).
 * - `language_bases`: every language it speaks, without the region, as `|en|hi|`, to narrow by.
 * - `search_text`: the words a search looks through, in lower case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_voices', function (Blueprint $table) {
            $table->id();
            $table->string('provider_id');
            $table->string('voice_id');
            $table->unsignedInteger('position');
            $table->text('name');
            $table->text('language')->nullable();
            $table->text('language_bases');
            $table->text('gender')->nullable();
            $table->text('accent')->nullable();
            $table->text('style')->nullable();
            $table->text('use_case')->nullable();
            $table->text('description')->nullable();
            $table->text('tags');
            $table->boolean('recommended')->default(false);
            $table->text('search_text');
            $table->unique(['provider_id', 'voice_id']);
            $table->index(['provider_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_voices');
    }
};
