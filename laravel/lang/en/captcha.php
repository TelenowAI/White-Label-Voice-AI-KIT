<?php

/**
 * The bot check (App\Kit\Captcha\Captcha, EnsureCaptcha, Admin\CaptchaController): each provider's name, the forms it
 * can guard, and the words its refusals answer with.
 */
return [
    'providers' => [
        'turnstile' => 'Cloudflare Turnstile',
        'hcaptcha' => 'hCaptcha',
        'recaptcha' => 'Google reCAPTCHA (v2)',
    ],
    'forms' => [
        'sign_up' => 'Signing up',
        'sign_in' => 'Signing in with a password',
        'password_reset' => 'Asking to reset a password',
        'sign_in_link' => 'Asking for a sign-in link',
        'sign_in_email_code' => 'Asking for an emailed sign-in code',
        'sign_in_code' => 'Asking for a sign-in code to a phone',
    ],
    'fields' => [
        'provider' => 'Bot check',
        'siteKey' => 'Site key',
        'secret' => 'Secret key',
        'forms' => 'Forms it guards',
    ],
    'failed' => 'Please complete the check that you are not a robot, then try again.',
    'secret_needed' => 'Enter the secret key.',
    'secret_refused' => 'The provider did not accept this secret key.',
    'unreachable' => 'The provider could not be reached to check the keys. Try again.',
];
