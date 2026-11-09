<?php

// Admin → Platform billing (App\Kit\Telenow\PlatformBilling): the operator's own account at the voice platform. Staff read these, never customers.
return [
    'alert_shape' => 'Give the level in USD: a whole number of up to :digits digits, with at most two decimals. Leave it empty for none.',
    'alert_refused' => 'The voice platform did not take that level.',
    'unavailable' => 'The voice platform could not be reached just now. Try again shortly.',
    'not_connected' => 'This platform is not connected to the voice platform yet.',
    'stopped_by' => [
        'out_of_credit' => 'it is out of credit',
        'spend_cap' => 'it reached its monthly spend cap',
        'suspended' => 'it is suspended',
        'other' => 'for a reason it did not give',
    ],
];
