<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A call's sentiment as its own column (App\Kit\Calls\Calls fills it when the call's analysis arrives), so insights count calls by it without
 * reading every call's analysis. The calls read before this get theirs from the analysis already kept, a page at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('sentiment', 32)->nullable();
        });
        DB::table('calls')->whereNotNull('analysis')->orderBy('id')->select(['id', 'analysis'])->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $sentiment = json_decode((string) $row->analysis, true)['sentiment'] ?? null;
                if (is_string($sentiment) && trim($sentiment) !== '') {
                    DB::table('calls')->where('id', $row->id)->update(['sentiment' => mb_substr(mb_strtolower(trim($sentiment)), 0, 32)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('sentiment');
        });
    }
};
