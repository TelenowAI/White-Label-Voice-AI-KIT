<?php

// An account's own voices (App\Kit\Voices\OwnVoices, Console\OwnVoicesController). Customers read these: nothing names the platform, a vendor's own message or an id.
return [
    'not_found' => 'No such voice in this account.',
    'managers_only' => 'Only the account owner can make, clone or delete the account\'s own voices.',
    'not_allowed' => 'Cloning voices is not available on this service.',
    'busy' => 'Another voice of this account is being made or cloned. Try again in a moment.',
    'voices_full' => 'This account has as many own voices as it may (:limit). Delete one first.',
    'clones_full' => 'This account has as many cloned voices as it may (:limit). Remove one first.',
    'confirm_release' => 'Confirm that the account holds this person\'s signed release to clone their voice.',
    'needs_sample' => 'Add a recording of the person speaking before cloning.',
    'already_cloned' => 'This voice is already cloned on :provider. Remove that clone first to make it again.',
    'cannot_clone_there' => 'This voice cannot be cloned on that provider. Choose one of the providers offered for cloning.',
    'sample_unusable' => 'This recording could not be used. Use at least :seconds seconds of clear speech, as WAV, MP3, OGG or WebM.',
    'sample_too_big' => 'This recording is too large. It may be at most :mb MB.',
    'no_such_clone' => 'This voice is not cloned on that provider.',
    'in_use' => 'These agents speak with this voice: :agents. Give them another voice first.',
    'gone' => 'This voice is no longer on the voice platform. Delete it here and make it again.',
    'refused' => 'The voice platform did not accept this. Try again later, or contact support.',
    'unavailable' => 'Voices cannot be changed right now. Try again shortly.',
    'fields' => [
        'name' => 'Voice name',
        'personName' => 'Whose voice it is',
        'relation' => 'How they relate to the account',
        'confirmed' => 'Their signed release',
        'language' => 'Language',
        'script' => 'What they read',
        'source' => 'How the recording is made',
        'file' => 'Recording',
        'seconds' => 'Length',
        'provider' => 'Voice provider',
    ],
];
