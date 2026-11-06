<?php

// Signing in and out (App\Http\Controllers\Kit\SessionController, the kit-sign-in limiter), and password reset
// (PasswordController, App\Kit\Auth\PasswordResets, the kit-password limiter).
return [
    'failed' => 'These details do not match an account.',
    'throttled' => 'Too many tries. Wait a minute and try again.',
    'reset_sent' => 'If an account uses that address, a link to choose a new password is on its way.',
    'reset_unavailable' => 'Password reset is not available. Ask your administrator.',
    'reset_gone' => 'This link no longer works. Ask for a new one.',
    'fields' => [
        'password' => 'Password',
    ],
];
