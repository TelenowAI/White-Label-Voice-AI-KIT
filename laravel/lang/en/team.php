<?php

// A customer account's team: members and invites (App\Kit\Team, the team controllers).
return [
    'fields' => [
        'email' => 'Email',
        'role' => 'Role',
        'name' => 'Your name',
        'password' => 'Password',
    ],
    'already_member' => 'This person is already a member of this account.',
    'no_invite' => 'This invite link is not valid. It may have been used, withdrawn or expired.',
    'no_member' => 'There is no such member.',
    'sign_in_first' => 'This email already has an account. Sign in to it first, then join.',
    'last_manager' => 'An account needs at least one person who manages it.',
    'managers_only' => 'Only the people who manage this account can change its team.',
];
