<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Telenow writes that could not be sent at once (App\Kit\Outbox). On shared hosting the one cron line
// `* * * * * php artisan schedule:run` drives this.
Illuminate\Support\Facades\Schedule::command('kit:outbox')->everyMinute()->withoutOverlapping();

// Whether Telenow lets the operator's account spend, for the console's "service paused" notice (App\Kit\Console\ServicePause).
Illuminate\Support\Facades\Schedule::command('kit:telenow-status')->cron('*/'.config('kit.telenow.status_minutes').' * * * *')->withoutOverlapping();

// Telenow's events: keep the kit's endpoint alive, apply what is due (App\Kit\Events).
Illuminate\Support\Facades\Schedule::command('kit:events')->everyMinute()->withoutOverlapping();

// The kit's copy of customers' calls: re-read unfinished calls, read each account's calls feed (App\Kit\Calls).
Illuminate\Support\Facades\Schedule::command('kit:calls')->everyMinute()->withoutOverlapping();

// Customers' wallets: charge rated calls, keep spend limits at Telenow current (App\Kit\Wallet).
Illuminate\Support\Facades\Schedule::command('kit:wallet')->everyMinute()->withoutOverlapping();

// Customers' do-not-call lists: Telenow's own entries and removals (App\Kit\Dnc).
Illuminate\Support\Facades\Schedule::command('kit:dnc')->everyMinute()->withoutOverlapping();

// Whether customers' calls need consent, told to each account; writes of their records given up, tried again (App\Kit\Consent).
Illuminate\Support\Facades\Schedule::command('kit:consent')->everyMinute()->withoutOverlapping();

// Customers' numbers and number fees (App\Kit\Numbers, App\Kit\Wallet\FeeCharges).
Illuminate\Support\Facades\Schedule::command('kit:numbers')->everyMinute()->withoutOverlapping();

// The kit's copy of the voice platform's catalog (App\Kit\Catalog).
Illuminate\Support\Facades\Schedule::command('kit:catalog')->hourly()->withoutOverlapping();

// Customers' knowledge: whether each document was read, and deletions (App\Kit\Knowledge).
Illuminate\Support\Facades\Schedule::command('kit:knowledge')->everyMinute()->withoutOverlapping();

// Customers' campaigns: where each stands and how each call went (App\Kit\Campaigns).
Illuminate\Support\Facades\Schedule::command('kit:campaigns')->everyMinute()->withoutOverlapping();

// Transfer taken off customers' agents after the operator turned transfers off (App\Kit\Agents\AgentTransfers).
Illuminate\Support\Facades\Schedule::command('kit:agents')->everyMinute()->withoutOverlapping();

// Emails no sender could send at once, tried again (App\Kit\Email).
Illuminate\Support\Facades\Schedule::command('kit:emails')->everyMinute()->withoutOverlapping();

// Payments still open, asked about with their gateways; expired ones given up (App\Kit\Payments).
Illuminate\Support\Facades\Schedule::command('kit:payments')->everyMinute()->withoutOverlapping();

// Sign-ups nobody verified, deleted once their link is long expired (App\Kit\Signup).
Illuminate\Support\Facades\Schedule::command('kit:signups')->daily()->withoutOverlapping();

// The money check between the kit and Telenow, a few customers at a time (App\Kit\Health\Reconciliation).
Illuminate\Support\Facades\Schedule::command('kit:reconcile')->everyFiveMinutes()->withoutOverlapping();
