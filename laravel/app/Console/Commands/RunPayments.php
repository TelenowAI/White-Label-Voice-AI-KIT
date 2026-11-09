<?php

namespace App\Console\Commands;

use App\Kit\Payments\Payments;
use Illuminate\Console\Command;

/**
 * `php artisan kit:payments`: ask each gateway about its payments still open, give up the ones past their expiry,
 * and delete gateway events past their keeping time (App\Kit\Payments\Payments::run); then issue the invoices of payments credited without
 * one (App\Kit\Invoices\CustomerInvoices::catchUp). Scheduled every minute.
 */
final class RunPayments extends Command
{
    protected $signature = 'kit:payments';

    protected $description = 'Check open payments with their gateways and give up expired ones';

    public function handle(Payments $payments, \App\Kit\Invoices\CustomerInvoices $invoices): int
    {
        ['checked' => $checked, 'expired' => $expired, 'purged' => $purged] = $payments->run();
        $issued = $invoices->catchUp(); // payments credited whose invoice could not be issued at once
        $this->info("{$checked} payment(s) checked, {$expired} given up, {$purged} old event(s) deleted, {$issued} invoice(s) issued late");

        return self::SUCCESS;
    }
}
