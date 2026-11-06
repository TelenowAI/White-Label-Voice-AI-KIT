<?php

// The operator's prices (App\Kit\Pricing\Pricing, PricingController). Admin only.
return [
    'currency_first' => 'Set the currency you bill in first.',
    'one_currency' => 'Every price list bills in :currency, the currency set for the platform.',
    'name_required' => 'Give the plan a name.',
    'currency_code' => 'A three-letter currency code, such as USD.',
    'currency_fixed' => 'The currency cannot change once price lists or wallet entries use it.',
    'rate_positive' => 'How much of your currency one US dollar is, above zero, such as 83.25.',
    'unknown_plan' => 'There is no such price list.',
    'extends_unknown' => 'List :list falls back to :missing, which does not exist.',
    'extends_cycle' => 'List :list falls back to itself through the lists it extends.',
    'in_use_default' => 'This is the default plan. Choose another default first.',
    'in_use_customers' => 'Customers are on this plan. Move them to another plan first.',
    'in_use_extends' => 'Other lists fall back to this one: :lists.',
    'not_found' => 'There is no such price list.',
];
