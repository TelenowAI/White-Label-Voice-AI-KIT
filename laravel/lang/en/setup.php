<?php

// The setup wizard's text (App\Kit\Setup): each step's title, description, labels and findings under its id,
// and the wizard's refusals. The operator reads these; customers never see the wizard.
return [
    'steps' => [
        'environment' => [
            'title' => 'Server check',
            'description' => 'Whether this server can run the platform. Fix anything marked as blocking, then reload.',
            'php' => 'PHP :version',
            'extensions_ok' => 'Every PHP extension the platform needs is installed',
            'extensions_missing' => 'PHP extensions are missing; ask your host to enable them',
            'writable' => ':path can be written',
            'not_writable' => ':path cannot be written',
            'https' => 'The site is served over HTTPS',
            'no_https' => 'The site is not served over HTTPS',
            'no_https_detail' => 'Telenow sends call events only to https addresses. Switch the site to HTTPS before connecting events.',
            'uploads' => 'Uploads up to :size',
            'uploads_unlimited' => 'Uploads have no size limit',
            'site_folder_public' => "The site is served from the platform's public/ folder",
            'site_folder_root' => "The site is served from the platform's own folder; its .htaccess sends every request into public/, keeping the platform's files private",
            'site_folder_root_unsafe' => "The site is served from the platform's own folder, and this web server may not read .htaccess files",
            'site_folder_root_unsafe_detail' => "The platform's own files (its settings among them) could be read from the web. Point the site at the public/ folder instead.",
        ],
        'database' => [
            'title' => 'Database',
            'description' => 'Where the platform keeps its data. The connection is tested and the tables are created before anything is saved.',
            'connection' => 'Database type',
            'fields' => [
                'host' => 'Host',
                'port' => 'Port',
                'database' => 'Database name',
                'username' => 'User name',
                'password' => 'Password',
            ],
            'help' => [
                'database' => 'For SQLite, the name of the database file.',
                'port' => 'Leave empty for the usual port.',
                'password' => 'Leave empty to keep the saved password.',
            ],
            'connected' => 'Connected (:connection)',
            'not_connected' => 'Not connected yet',
            'connect_failed' => 'Could not connect: :error',
            'migrate_failed' => 'Connected, but the tables could not be created: :error',
            'env_failed' => 'Connected and the tables are made, but the settings could not be saved: :error',
            'sqlite_failed' => 'Could not create the database file :path',
        ],
        'admin' => [
            'title' => 'Your account',
            'description' => 'The owner account of this platform. Only this account can finish the setup.',
            'fields' => [
                'name' => 'Your name',
                'email' => 'Email',
                'password' => 'Password',
            ],
            'help' => [
                'password' => 'At least :min characters.',
            ],
        ],
        'telenow' => [
            'title' => 'Telenow account',
            'description' => 'The API key of your own Telenow account. Every customer you add becomes a sub-account of it.',
            'fields' => [
                'apiKey' => 'API key',
            ],
            'help' => [
                'apiKey' => 'An owner or admin key of your top-level account. It is stored encrypted and never shown again.',
            ],
            'facts' => [
                'account' => 'Account',
                'key' => 'Key',
                'mode' => 'Billing',
                'can_spend' => 'Calls',
            ],
            'modes' => [
                'prepaid' => 'Prepaid',
                'postpaid' => 'Postpaid',
            ],
            'can_spend_yes' => 'Telenow lets this account make calls now',
            'can_spend_no' => 'Paused: :why',
            'stopped_by' => [
                'suspended' => 'the account is suspended',
                'out_of_credit' => 'the account has no credit left',
                'spend_cap' => 'the account has reached its spend cap',
                'unknown' => 'Telenow did not say why',
            ],
            'paused' => 'Telenow will not let this account spend right now. You can finish the setup, but the platform stays paused until it can.',
            'billing_unread' => 'The account\'s billing could not be read',
            'no_spend_state' => 'Telenow did not say whether the account can spend.',
            'unreachable' => 'Telenow could not be reached: :error',
            'refused' => 'Telenow refused this key: :error',
            'sub_account' => 'This key belongs to a sub-account (:org). Use a key of your own top-level account.',
            'wrong_role' => 'This key\'s role is :role. The platform needs a key with one of these roles: :roles.',
        ],
        'brand' => [
            'title' => 'Brand',
            'description' => 'How your platform looks to your customers. You can change all of it later.',
            'fields' => [
                'name' => 'Platform name',
                'logoUrl' => 'Logo address',
                'supportEmail' => 'Support email',
                'color' => 'Colour: :role',
            ],
        ],
        'email' => [
            'title' => 'Email',
            'description' => 'How your platform sends email: invites, password resets and receipts. Saving sends you a test email first.',
            'none' => 'Not now (no email: invite links are shown instead, and nobody can reset a password)',
        ],
        'signup' => [
            'title' => 'Sign-ups',
            'description' => 'Who may sign up for an account on their own. You can change it, and add your own form fields, terms and a trial credit, in Admin → Sign-ups.',
            'domains_help' => 'For example acme.com, acme.co.in',
        ],
        'payments' => [
            'title' => 'Payments',
            'description' => 'How customers add credit to their wallet. Start with a bank transfer, or skip this: more ways to pay, and the top-up rules, are in Admin → Payments.',
            'none' => 'Not now (customers get credit only when you add it by hand)',
        ],
        'finish' => [
            'title' => 'Finish',
            'description' => 'Lock the setup and open the admin panel.',
        ],
    ],
    'refusals' => [
        'installed' => 'The platform is already set up.',
        'unknown_step' => 'There is no setup step ":step".',
        'nothing_to_submit' => ':step has nothing to send.',
        'earlier_step' => 'Finish ":step" first.',
        'closed' => ':step is already done.',
        'sign_in' => 'Sign in as the owner to continue.',
        'owner_only' => 'Only the owner can do this.',
    ],
];
