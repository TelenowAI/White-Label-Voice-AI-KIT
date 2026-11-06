<?php

// Telenow's events endpoint (App\Kit\Events): answers Telenow and the operator read. Customers never see these.
return [
    'refused' => [
        '400' => 'The delivery has no id, or its body is not JSON.',
        '401' => 'The signature does not match.',
        '503' => 'This kit has no events endpoint yet. Try again later.',
    ],
    'no_secret' => 'The voice platform registered the events endpoint but answered no signing secret.',
];
