<?php

/**
 * The admin dashboard and Cost vs price (App\Kit\Reports): the names of what a charge is made of — a price line's
 * subject (`call`, `component:<name>`, `fee:<kind>`); a subject with no name here shows as it is — and the report's
 * own words. Staff read these; customers never do.
 */
return [
    'subjects' => [
        'call' => 'Calls, priced all in',
    ],
    'components' => [
        'llm' => 'Language model',
        'stt' => 'Speech to text',
        'tts' => 'Text to speech',
        'telephony' => 'Phone line',
        'platformFee' => 'Platform fee',
        'platformAi' => 'Platform AI',
        'postCallAnalysis' => 'Call analysis',
        'unlisted' => 'Other charges on the call',
    ],
    'fees' => [
        'rental' => 'Number rent',
        'number_setup' => 'Number setup',
        '' => 'Other fees',
    ],
    'no_plan' => 'No plan',
    'unknown_customer' => 'A removed customer',
    'groups' => [
        'customer' => 'Customer',
        'component' => 'What was charged',
        'plan' => 'Plan',
        'day' => 'Day',
    ],
    'fields' => [
        'from' => 'From',
        'to' => 'To',
    ],
    'too_long' => 'A report covers at most :days days.',
    'rebuilt' => 'Wrote the daily totals again from :count charges.',
];
