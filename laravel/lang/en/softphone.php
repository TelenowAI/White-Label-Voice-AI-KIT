<?php

// Calls a member places from the browser (App\Kit\Softphone, SoftphoneController). Customers read these: nothing here names the voice platform or its own messages.
return [
    'fields' => [
        'callerId' => 'Call from',
        'number' => 'Number to call',
        'to' => 'Transfer to',
    ],
    'not_allowed' => 'Calls from the browser are not available on this service.',
    'transfer_not_allowed' => 'Transfers are not available on this service.',
    'not_priced' => 'Calls from the browser are not priced on your plan yet, so they cannot be placed.',
    'pick_caller_id' => 'Pick one of this account\'s numbers.',
    'caller_id_gone' => 'That number is no longer on this account.',
    'caller_id_busy' => 'That number is already on as many calls as it can take at once. Try again in a few minutes, or call from another.',
    'number_needs_plus' => 'Write the number with + and its country code, like +14155550100.',
    'on_dnc' => 'This number is on your do-not-call list.',
    'needs_consent' => 'Your calls need consent on file, and this number has none.',
    'refused' => 'This number cannot be called: it is on the do-not-call list, or needs consent on file.',
    'unavailable' => 'The call could not be placed right now. Try again shortly.',
    'not_a_browser_call' => 'Only a call placed from the browser can be ended or transferred here.',
    'not_yours' => 'Only the person who placed this call, or someone who manages this account, can do this.',
    'call_over' => 'This call has already ended.',
    'transfer_failed' => 'The call could not be transferred. It is still on.',
    'hang_up_failed' => 'The call could not be ended from here. Try again, or close the call in the browser.',
];
