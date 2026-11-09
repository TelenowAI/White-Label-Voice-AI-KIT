<?php

// A customer's do-not-call list (App\Kit\Dnc, DncController). Customers read these: nothing here names the voice platform.
return [
    'fields' => [
        'phoneNumber' => 'Number',
        'reason' => 'Reason',
        'csv' => 'File',
    ],
    'not_found' => 'That number is not on the list.',
    'not_dialable' => 'This is not a phone number that can be dialled.',
    'account_not_ready' => 'Your account is still being set up. Try again in a moment.',
    'will_retry' => 'Not saved yet. It will be tried again shortly.',
    'cancelled' => 'Removed before it was saved.',
    'import_too_big' => 'The file is too big: :mb MB at most.',
    'import_refused' => 'The file could not be read as a list of numbers: one number per line, then a comma and a reason if you like.',
    'import_unavailable' => 'The file could not be imported just now. Try again shortly.',
    'export_file' => 'do-not-call',
    'export_columns' => ['number' => 'Number', 'reason' => 'Reason', 'source' => 'Source', 'added' => 'Added'],
];
