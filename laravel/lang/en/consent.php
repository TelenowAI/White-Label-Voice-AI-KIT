<?php

// A customer's record of who agreed to be called (App\Kit\Consent, ConsentController). Customers read these: nothing here names the voice platform or its own messages.
return [
    'fields' => [
        'phoneNumber' => 'Number',
        'consentType' => 'Kind of consent',
        'scope' => 'What for',
        'source' => 'How it was given',
        'disclosureText' => 'The exact words they agreed to',
        'disclosureVersion' => 'Version of those words',
        'capturedAt' => 'When they agreed',
        'expiresAt' => 'When it ends',
        'note' => 'Note',
        'csv' => 'File',
        'required' => 'Calls need consent',
    ],
    'account_not_ready' => 'Your account is still being set up. Try again in a moment.',
    'number_needs_plus' => 'Write the number with + and its country code, like +14155550100. Calls are matched to consent exactly.',
    'written_needs_words' => 'Written consent needs the exact words they agreed to, not a link to them.',
    'no_phone_column' => 'The file needs a column named :columns.',
    'too_many_rows' => 'At most :max people at a time.',
    'import_needs_date' => 'Say when they agreed: a date for the whole file, or a :column column.',
    'refused' => 'This could not be recorded. Check the number and try again.',
    'nothing_to_withdraw' => 'There is no consent on file for this number.',
    'still_withdrawing' => 'This number is still being withdrawn. Try again in a moment.',
    'required_by_operator' => 'Your provider requires consent for every call, so this cannot be turned off.',
    'held_by_provider' => 'The account your service runs under requires consent, so this cannot be turned off here.',
    'only_managers' => 'Only someone who manages this account can change this.',
    'export_file' => 'consent',
    'export_columns' => [
        'number' => 'Number', 'type' => 'Consent', 'scope' => 'Covers', 'source' => 'Source', 'disclosureVersion' => 'Disclosure version',
        'given' => 'Given', 'expires' => 'Ends', 'withdrawn' => 'Withdrawn', 'note' => 'Note',
    ],
];
