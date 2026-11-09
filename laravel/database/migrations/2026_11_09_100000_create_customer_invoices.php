<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers' invoices (App\Kit\Invoices\CustomerInvoices): one for each payment credited to a customer's wallet, numbered without gaps per calendar
 * year (`invoice_counters`), with the operator's and the customer's details and the tax as they were when it was issued (never changed after); and
 * the customer's own billing details (their business name, address and tax number) printed on the invoices issued from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUlid('workspace_id', 36)->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id', 36)->unique()->constrained()->cascadeOnDelete();
            $table->string('number', 64)->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->timestamp('issued_at');
            $table->string('currency', 3);
            $table->string('amount');
            $table->string('net');
            $table->string('tax')->nullable();
            $table->string('tax_name', 64)->nullable();
            $table->string('tax_rate', 16)->nullable();
            $table->text('description');
            $table->longText('seller');
            $table->longText('buyer');
            $table->timestamps();
            $table->unique(['year', 'sequence']);
            $table->index(['workspace_id', 'issued_at']);
        });
        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last');
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('billing_name')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('billing_tax_number', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['billing_name', 'billing_address', 'billing_tax_number']);
        });
        Schema::dropIfExists('invoice_counters');
        Schema::dropIfExists('customer_invoices');
    }
};
