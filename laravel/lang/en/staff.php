<?php

/**
 * Admin → Staff (App\Kit\Staff): the operator's team — the roles' names and what each may do (their abilities are
 * config('kit.admin.abilities')), the form's fields, and the refusals. Staff read these; customers never do.
 */
return [
    'roles' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'support' => 'Support',
    ],
    'about' => [
        'owner' => 'Everything, including the team and payment gateways.',
        'admin' => 'Everything except the team and payment gateways.',
        'support' => 'Customers (read, suspend, resume) and the voice platform page. No money.',
    ],
    'fields' => [
        'email' => 'Email',
        'role' => 'Role',
        'name' => 'Your name',
        'password' => 'Password',
    ],
    'not_allowed' => 'Your role cannot do this.',
    'already_staff' => 'This address is already on the team.',
    'customer_address' => "This address belongs to a customer's account. Staff use an address of their own.",
    'no_invite' => 'This invite link is not valid any more. Ask for a new one.',
    'no_member' => 'There is no one on the team with that account.',
    'last_owner' => 'The team needs an owner: make someone else owner first.',
];
