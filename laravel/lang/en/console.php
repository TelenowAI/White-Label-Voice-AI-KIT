<?php

// The customer console (App\Kit\Console, the console controllers). Customers read these: nothing here names the
// voice platform behind the service, or why the operator's own account stopped (white label).
return [
    'no_account' => 'You are not a member of any account yet. Ask for an invite.',
    'view_as_read_only' => 'You are looking at this account as staff: nothing can be changed.',
    'not_your_account' => 'There is no such account among yours.',
    'paused' => [
        'account_closed' => 'This account is closed.',
        'account_suspended' => 'Your account is suspended. Contact support to restore it.',
        'account_not_ready' => 'Your account is still being set up. This usually takes a moment.',
        'out_of_credit' => 'Your balance is used up. Calls are on hold until credit is added.',
        'service_paused' => 'The service is paused for now. Calls and purchases are on hold until it is back.',
    ],
];
