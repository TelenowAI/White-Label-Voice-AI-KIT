<?php

// Voices and previews (App\Kit\Catalog, VoicesController). Customers read these: nothing names the platform or a vendor's
// own message.
return [
    'preview_text' => 'Hello! Thanks for calling. How can I help you today?',
    'unknown_voice' => 'Choose one of the voices offered.',
    'preview_busy' => 'Previews are limited to a few at a time. Try again in a moment.',
    'preview_unavailable' => 'This voice cannot be previewed right now. Try again shortly.',
    'preview_refused' => 'This voice could not be previewed.',
    'stars_full' => 'You have starred as many voices as you may (:max). Unstar some first.',
    'fields' => [
        'provider' => 'Voice provider',
        'voice' => 'Voice',
        'text' => 'Text',
        'language' => 'Language',
        'gender' => 'Gender',
        'search' => 'Search',
    ],
];
