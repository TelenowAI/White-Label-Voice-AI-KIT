<?php

// Admin → Customers (App\Kit\Customers, CustomersController) and the admin panel's guards.
return [
    'not_found' => 'There is no such customer.',
    'already_suspended' => 'This customer is already suspended.',
    'already_active' => 'This customer is already active.',
    'already_closing' => 'This customer is being closed.',
    'already_closed' => 'This customer is closed.',
    'confirm_close' => "Type the customer's name exactly to confirm.",
    'closing_reason' => 'Closing the account',
    'closed_before_made' => 'Closed before the voice platform made it.',
    'numbers_releasing' => 'Waiting for :count numbers to be released first.',
    'calls_landing' => 'Waiting a moment for any last call to land.',
    'calls_ending' => 'Waiting for live calls to end (:count now).',
    'calls_settling' => 'Waiting for the last calls to be read and charged. This can take a few hours.',
    'fields' => [
        'max_concurrent_calls' => 'Most calls at once',
        'monthly_cap_usd' => 'Monthly cap (USD)',
    ],
    'nothing_to_retry' => 'Nothing failed for this customer.',
    'no_operator_key' => 'No Telenow key is saved. Finish the setup first.',
    'not_installed' => 'Finish the setup first.',
    'sign_in' => 'Sign in to continue.',
    'staff_only' => 'Only the operator\'s staff can do this.',
    'not_made_yet' => 'The voice platform has not made this customer yet.',
    'no_total' => 'The voice platform did not say what this customer has spent.',
];
