<?php

// Customers' invoices (App\Kit\Invoices\CustomerInvoices): customers and the operator's staff read these. Nothing names the voice platform.
return [
    'line' => 'Credit for :account on :brand',
    'fields' => [
        'prefix' => 'Invoice number prefix',
        'name' => 'Name',
        'address' => 'Address',
        'taxNumber' => 'Tax number',
        'email' => 'Email',
        'taxName' => 'Tax name',
        'taxRate' => 'Tax rate',
    ],
    'rate_shape' => 'Give the tax rate as a percentage from 0 to 100, with at most two decimals.',
    'not_found' => 'There is no such invoice.',
    'managers_only' => "Only the account's owners and admins can change its billing details.",
];
