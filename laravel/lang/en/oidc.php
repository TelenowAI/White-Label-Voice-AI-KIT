<?php

/**
 * Signing in with an OpenID Connect provider (App\Kit\Oidc, OidcController, Admin\SsoController): each preset's name and
 * the words staff are answered with. The person signing in is told why a sign-in did not work in the console's own
 * words (it gets a reason, never these).
 */
return [
    'presets' => [
        'google' => 'Google',
        'microsoft' => 'Microsoft',
    ],
    // What the operator must set up at a preset's provider, beyond the client and the redirect address.
    'preset_help' => [
        'microsoft' => 'In Microsoft Entra, register a Web app for "any organizational directory and personal Microsoft accounts". Personal accounts then work. For work and school accounts to sign in the first time, add the optional ID token claims email and xms_edov under Token configuration: Microsoft does not otherwise say their address is verified.',
    ],
    'custom' => 'Another OpenID Connect provider',
    'fields' => [
        'preset' => 'Provider',
        'issuer' => 'Issuer',
        'name' => 'Button name',
        'clientId' => 'Client ID',
        'clientSecret' => 'Client secret',
        'enabled' => 'Enabled',
    ],
    'too_many' => 'You can add up to :max sign-in providers.',
    'order_mismatch' => 'List every provider once, in the new order.',
    'unknown_provider' => 'There is no such sign-in provider.',
    'issuer_https' => 'The issuer must be an https address.',
    'issuer_unreadable' => "This provider's OpenID configuration could not be read: :reason",
];
