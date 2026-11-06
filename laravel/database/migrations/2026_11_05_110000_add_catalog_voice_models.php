<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The models a copied voice speaks with (App\Kit\Catalog\CatalogVoices): `|m1|m2|`, or empty for a provider with no choice of model. Some providers list other voices for each
 * model (Telenow's own engine, Rime, Sarvam: recorded, contract_k13settings.py), so a voice is offered only with a model it was listed for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_voices', function (Blueprint $table) {
            $table->string('models', 500)->default('');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_voices', function (Blueprint $table) {
            $table->dropColumn('models');
        });
    }
};
