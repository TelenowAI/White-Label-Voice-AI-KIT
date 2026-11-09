<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer invoice (the `customer_invoices` table), written only by App\Kit\Invoices\CustomerInvoices when a payment is credited, and never
 * changed after: `seller` and `buyer` are the operator's and the customer's details as they were then.
 */
final class CustomerInvoice extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'seller' => 'array', 'buyer' => 'array', 'year' => 'integer', 'sequence' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
