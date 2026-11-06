<?php

/**
 * The public website (App\Kit\Website, the default theme): the content a new install starts with — the operator
 * changes all of it in Admin → Website; `{brand}`, `{email}` and `{site}` are filled in when shown — and the theme's own
 * words (navigation, the pricing page's labels).
 */
return [
    'defaults' => [
        'tagline' => 'Voice agents that answer, call and follow up for you.',
        'meta_description' => '{brand}: AI voice agents for calls in and out, set up in minutes.',
        'hero_title' => 'Voice agents for every call',
        'hero_text' => '{brand} answers your calls, calls your customers back and follows up, in a voice that sounds like you.',
        'cta_title' => 'Ready when you are',
        'cta_text' => 'Create your account and make your first test call in a few minutes.',
        'features' => [
            ['title' => 'Answers every call', 'text' => 'Your agent picks up day and night, in the languages your customers speak.'],
            ['title' => 'Calls out for you', 'text' => 'Run campaigns that call a list, within the hours you set, and keep your do-not-call list.'],
            ['title' => 'Knows your business', 'text' => 'Give it your documents and web pages; it answers from them.'],
            ['title' => 'Pay as you go', 'text' => 'Add credit to your wallet and pay only for the minutes you use.'],
        ],
        'pricing_intro' => 'Pay only for what you use. Add credit to your wallet whenever you like.',
        'contact_text' => "Questions about {brand}? Write to us at {email} and we will get back to you.",
        'legal' => [
            'terms' => "These are placeholder terms for {brand}. Replace them with your own before you open sign-ups.\n\n# Using {brand}\n\nYou may use {brand} only as the law allows and as these terms say.\n\n# Contact\n\nWrite to {email}.",
            'privacy' => "This is a placeholder privacy policy for {brand}. Replace it with your own.\n\n# What we keep\n\n- Your account details\n- The calls your agents make and take\n\n# Contact\n\nWrite to {email}.",
        ],
    ],
    'skip' => 'Skip to the page',
    'nav' => [
        'label' => 'Site',
        'legal' => 'Legal',
        'home' => 'Home',
        'features' => 'Features',
        'pricing' => 'Pricing',
        'contact' => 'Contact',
        'sign_in' => 'Sign in',
        'sign_up' => 'Create an account',
    ],
    'legal_pages' => [
        'terms' => 'Terms of service',
        'privacy' => 'Privacy policy',
    ],
    'sections' => [
        'features' => 'Features',
        'pricing' => 'Pricing',
        'cta' => 'Call to action',
    ],
    'fields' => [
        'heroTitle' => 'Headline',
        'heroImage' => 'Hero image address',
    ],
    'features_title' => 'What it does',
    'pricing_title' => 'Pricing',
    'pricing_none' => 'Write to us for prices.',
    'see_pricing' => 'See pricing',
    'calls' => 'Calls',
    'calls_with' => 'Calls :which',
    'per_minute' => 'per minute',
    'increment' => 'Billed in steps of :seconds seconds.',
    'attributes' => [
        'channel' => ['web_call' => 'in the browser', 'telephony' => 'by phone'],
        'direction' => ['inbound' => 'incoming', 'outbound' => 'outgoing'],
    ],
    'fees' => [
        'rental' => 'Phone number, each month',
        'number_setup' => 'Phone number setup',
    ],
    'contact_title' => 'Contact',
    'rights' => '© :year :brand',
    'console_not_built' => 'The console is not built here. In development run it with `pnpm --filter console dev`; a release has it built in (scripts/release.mjs).',
];
