<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People on the phone (App\Kit\Softphone, App\Kit\Calls\CallWork): who placed a call from the browser, a call's wrap-up, its notes and follow-ups, and a call's two numbers as digits only,
 * so one person's calls are found by their number however it was written (Telenow keeps a caller ID with no +, the number called with one: recorded, contract_k9human.py).
 * Calls already kept get their digits here, a few hundred at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->unsignedBigInteger('placed_by')->nullable();
            $table->string('from_digits')->nullable();
            $table->string('to_digits')->nullable();
            $table->string('wrapup')->nullable();
            $table->unsignedBigInteger('wrapup_by')->nullable();
            $table->timestamp('wrapup_at')->nullable();
            $table->index(['workspace_id', 'from_digits']);
            $table->index(['workspace_id', 'to_digits']);
            $table->index(['workspace_id', 'wrapup_at']);
            $table->index(['workspace_id', 'placed_by', 'started_at']);
        });
        DB::table('calls')->select(['id', 'from_number', 'to_number'])->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('calls')->where('id', $row->id)->update([
                    'from_digits' => self::digits($row->from_number),
                    'to_digits' => self::digits($row->to_number),
                ]);
            }
        });
        Schema::create('call_notes', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('call_id', 36)->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('body');
            $table->timestamps();
            $table->index(['call_id', 'created_at']);
        });
        Schema::create('call_followups', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('call_id', 36)->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('body');
            $table->timestamp('due_at');
            $table->timestamp('done_at')->nullable();
            $table->unsignedBigInteger('done_by')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'user_id', 'done_at', 'due_at']);
            $table->index(['call_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_followups');
        Schema::dropIfExists('call_notes');
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'from_digits']);
            $table->dropIndex(['workspace_id', 'to_digits']);
            $table->dropIndex(['workspace_id', 'wrapup_at']);
            $table->dropIndex(['workspace_id', 'placed_by', 'started_at']);
            $table->dropColumn(['placed_by', 'from_digits', 'to_digits', 'wrapup', 'wrapup_by', 'wrapup_at']);
        });
    }

    private static function digits(?string $number): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $number);

        return $digits === '' ? null : $digits;
    }
};
