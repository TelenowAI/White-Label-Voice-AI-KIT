<?php

// A customer's phone numbers (App\Kit\Numbers, NumbersController). Customers read these: nothing names the platform,
// the carrier, or the operator's own account.
return [
    'fields' => [
        'country' => 'Country',
        'type' => 'Type',
        'pattern' => 'Digits',
        'number' => 'Number',
    ],
    'not_found' => 'There is no such number.',
    'account_not_ready' => 'Your account is still being set up. Try again in a moment.',
    'search_refused' => 'Numbers could not be searched with those details. Check the country code.',
    'search_unavailable' => 'Numbers could not be searched just now. Try again shortly.',
    'not_available' => 'That number is no longer available. Search again.',
    'no_price' => 'That number has no price on your plan yet. Ask support.',
    'not_enough_balance' => 'Your balance does not cover this number: :amount :currency is due now (setup and the first month). Add credit first.',
    'cannot_buy_now' => 'Numbers cannot be bought right now. Ask support.',
    'not_done' => 'This could not be done. Try again, or ask support.',
    'will_retry' => 'Not saved yet. It will be tried again shortly.',
    'not_bought_yet' => 'This number is still being bought. Try again in a moment.',
    'unknown_agent' => 'Choose one of your saved agents.',
    'cancelled' => 'Removed before it was bought.',
    'replaced' => 'Replaced by a later change.',
];
