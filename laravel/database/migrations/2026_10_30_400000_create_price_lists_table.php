<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's price lists, one per plan (App\Kit\Pricing\Pricing; contract/pricing/README.md): `definition` is the
 * list exactly as the pricing spec's format has it (contract/pricing/price-list.schema.json), its `id` the row's key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->longText('definition');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
