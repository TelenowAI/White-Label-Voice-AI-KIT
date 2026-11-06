<?php

/*
 * voice-kit's own settings: what the kit API answers before the setup wizard has stored the operator's
 * choices, and where the install's state lives. Every value here is a default; the operator's settings
 * (the `settings` table, from the wizard and admin) take precedence once they exist.
 *
 * Read by: App\Http\Controllers\Kit\* (the kit API, contract/kit-api.yaml), App\Kit\Setup\* (the setup
 * wizard), App\Kit\Telenow\TelenowClient.
 */

return [

    // The kit's release version: the VERSION file at this app's root (one line), the same file the
    // release packaging stamps.
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown',

    // The file the setup wizard writes when it finishes. While it is absent the kit is not installed:
    // the console sends visitors to the wizard, and the wizard's routes answer.
    'installed_marker' => storage_path('app/kit/installed'),

    // The brand the console and website use until the operator sets theirs.
    'brand' => [
        'name' => env('KIT_BRAND_NAME', 'Voice Platform'),
        'logo_url' => env('KIT_BRAND_LOGO_URL') ?: null,
        'support_email' => env('KIT_BRAND_SUPPORT_EMAIL') ?: null,
        // CSS colour values by role. The console sets each as a CSS variable named after its role,
        // so the operator can add roles without a code change.
        'colors' => [
            'primary' => '#4f46e5',
            'primary-contrast' => '#ffffff',
            'background' => '#ffffff',
            'surface' => '#f6f7f9',
            'text' => '#111827',
            'muted' => '#6b7280',
        ],
    ],

    'setup' => [
        // The wizard's steps, in the order they run (SELF_HOSTED_KIT_PLAN.md §4.1). Each class declares its
        // own form, checks and rules, and the console renders whatever it declares: a step is added here,
        // with no console change. The admin step comes before any secret is entered, so everything after it
        // is the signed-in owner's alone.
        'steps' => [
            App\Kit\Setup\Steps\EnvironmentStep::class,
            App\Kit\Setup\Steps\DatabaseStep::class,
            App\Kit\Setup\Steps\AdminStep::class,
            App\Kit\Setup\Steps\TelenowStep::class,
            App\Kit\Setup\Steps\BrandStep::class,
            App\Kit\Setup\Steps\EmailStep::class,
            App\Kit\Setup\Steps\SignupStep::class,
            App\Kit\Setup\Steps\PaymentsStep::class,
            App\Kit\Setup\Steps\FinishStep::class,
        ],

        // The environment file the wizard writes (APP_KEY, the database). Null: the one Laravel reads.
        'env_file' => null,

        // Where an SQLite database file may be made. The database step runs before anyone has signed in,
        // so it never touches a path outside this folder.
        'sqlite_dir' => database_path(),

        // The connection settings the database step asks for, each where the connection has it
        // (config/database.php). Saved as DB_<NAME>.
        'database_fields' => ['host', 'port', 'database', 'username', 'password'],

        // A connection whose PDO driver has another name than the connection's driver.
        'pdo_drivers' => ['mariadb' => 'mysql'],

        // Web servers that read .htaccess files (matched in SERVER_SOFTWARE): only on these may the site be served
        // from the platform's own folder, whose .htaccess sends every request into public/ (EnvironmentStep).
        'htaccess_servers' => ['Apache', 'LiteSpeed'],
    ],

    'telenow' => [
        // Telenow's API (its released document's production server).
        'base_url' => env('KIT_TELENOW_URL', 'https://api.telenow.ai'),
        // The operator's own Billing page at Telenow (recharge, invoices, the spend cap): linked from the admin dashboard.
        'billing_url' => env('KIT_TELENOW_BILLING_URL', 'https://app.telenow.ai/billing'),
        // What the dashboard keeps of Telenow's spend state for the operator's account (billing summary `spendState`).
        'spend_state_fields' => ['mode', 'state', 'canSpend', 'stoppedBy', 'balanceUsd', 'monthlySpendLimitUsd', 'periodSpendUsd',
            'periodStart', 'headroomUsd', 'lowThresholdUsd'],
        'timeout_seconds' => (int) env('KIT_TELENOW_TIMEOUT', 15),
        // The key roles that may manage sub-accounts: Telenow checks the key's role is one of these
        // (sub_accounts/service.rs), so the operator's key must have one.
        'operator_roles' => ['owner', 'admin'],
        // How often `kit:telenow-status` reads whether Telenow lets the operator's account spend (minutes).
        'status_minutes' => (int) env('KIT_TELENOW_STATUS_MINUTES', 5),
        // The platform's own name. Customers never see it: a catalog name that carries it is never offered until the
        // operator gives it a name of their own (App\Kit\Catalog\Catalog).
        'name' => env('KIT_TELENOW_NAME', 'Telenow'),
    ],

    'catalog' => [
        // How often `kit:catalog` copies Telenow's catalog again (hours); Admin → Catalog can refresh it at once.
        'refresh_hours' => (int) env('KIT_CATALOG_REFRESH_HOURS', 24),
        // The catalog's lists customers choose from (getCatalog's llm, stt and tts), each the agent's stack columns it
        // sets: the provider, then (llm) its model or (tts) its voice.
        'kinds' => [
            'llm' => ['provider' => 'llm_provider', 'model' => 'llm_model'],
            'stt' => ['provider' => 'stt_provider'],
            'tts' => ['provider' => 'tts_provider', 'voice' => 'tts_voice'],
        ],
        // What the kit keeps of each (an allow-list: a field Telenow adds later — a price — is never copied). Voices keep what
        // App\Kit\Catalog\CatalogVoices::row names.
        'fields' => [
            'provider' => ['id', 'name', 'status', 'enabled', 'recommended', 'multilingual'],
            'model' => ['id', 'name', 'enabled', 'recommended', 'contextWindow'],
        ],
        // Voices per page in the console.
        'voices_per_page' => (int) env('KIT_VOICES_PER_PAGE', 24),
        // Voices written to the database at a time when a provider's list is copied.
        'voices_write_chunk' => (int) env('KIT_VOICES_WRITE_CHUNK', 200),
        // The longest preview text Telenow takes.
        'preview_text_max' => 500,
        // Realtime (speech-to-speech) voices (App\Kit\Catalog\RealtimeCatalog, Telenow's getRealtimeCatalog): what the kit keeps of each provider, model and voice (an allow-list:
        // never a price, a key's name or a field Telenow adds later), and how many realtime clips a member may ask for a minute (each opens a paid realtime session; Telenow
        // takes one an account every 6 s, recorded).
        'realtime' => [
            'fields' => [
                'provider' => ['id', 'name', 'status', 'recommended', 'languages'],
                'model' => ['id', 'name'],
                'voice' => ['id', 'name', 'gender', 'accent', 'tags', 'recommended'],
            ],
            'previews_per_minute' => (int) env('KIT_REALTIME_PREVIEWS_PER_MINUTE', 6),
            // The blob an agent's realtime voice lives in at Telenow (written camelCase, read snake_case), and the keys the kit sets in it; any other key there stays.
            'blob' => ['write' => 's2sConfig', 'read' => 's2s_config'],
            'keys' => ['provider', 'model', 'voice', 'fallback'],
        ],
        // Each provider's own settings (getCatalog `configFields`: a voice's model, quality and speed, a listening model's language, a language model's temperature), as
        // App\Kit\Catalog\ProviderSettings copies and offers them. What a field keeps (an allow-list), which kinds of field customers get until the operator says otherwise
        // (bounded ones: free text can carry an address or an id, so the operator turns each such field on), and which kinds are never offered (a vendor descriptor).
        // A field Telenow marks `sensitive` (a key, a token) is never copied. Telenow keeps whatever it is sent (recorded, contract_k13settings.py), so the kit checks each value.
        'settings' => [
            'field' => ['key', 'kind', 'label', 'help', 'options', 'min', 'max', 'step', 'defaultValue', 'advanced'],
            'offered_kinds' => ['select', 'number', 'toggle'],
            'never_kinds' => ['json'],
            // Never copied, whatever the catalog says of them: a field Telenow keeps as a secret (its src/utils/secrets.rs SENSITIVE_FIELDS, encrypted and shown masked,
            // `accessKeyId` among them, which the catalog does not mark `sensitive`), or one named for where a call is sent (its is_destination_field: `provider`, or a name
            // ending so). A customer's value there would send the platform's requests, or a key, where they choose. Names compared as Telenow compares them: letters and
            // digits only, lower case.
            'never_keys' => ['apikey', 'bearer', 'token', 'authtoken', 'accountsid', 'clientsecret', 'authorization', 'xapikey', 'password', 'passphrase', 'privatekey',
                'accesskeyid', 'secretaccesskey', 'sessiontoken', 'provider'],
            'never_key_endings' => ['url', 'urls', 'uri', 'uris', 'endpoint', 'endpoints', 'host', 'hosts', 'hostname', 'domain', 'domains', 'server', 'servers', 'region',
                'location', 'apibase'],
            'text_max_length' => 1000,
            // Where Telenow's catalog names the field that holds a speech model (`models[].configKey`), when it names none.
            'model_key' => 'model',
            // The blob each kind's settings live in at Telenow (written camelCase, read snake_case); a language model's own model is the top-level llmModel.
            'blobs' => ['llm' => ['write' => 'llmConfig', 'read' => 'llm_config'], 'stt' => ['write' => 'sttConfig', 'read' => 'stt_config'], 'tts' => ['write' => 'ttsConfig', 'read' => 'tts_config']],
        ],
    ],

    'outbox' => [
        // Who sends each Telenow write: '<resource>.<operation>' => handler (App\Kit\Outbox\OutboxHandler).
        'handlers' => [
            'workspace.create' => App\Kit\Customers\Telenow\CreateAtTelenow::class,
            'workspace.suspend' => App\Kit\Customers\Telenow\SuspendAtTelenow::class,
            'workspace.resume' => App\Kit\Customers\Telenow\ResumeAtTelenow::class,
            'workspace.limit' => App\Kit\Customers\Telenow\SetSpendLimitAtTelenow::class,
            'workspace.limits' => App\Kit\Customers\Telenow\SetLimitsAtTelenow::class,
            'workspace.close' => App\Kit\Customers\Telenow\CloseAtTelenow::class,
            'agents.create' => App\Kit\Agents\Telenow\CreateAtTelenow::class,
            'agents.update' => App\Kit\Agents\Telenow\UpdateAtTelenow::class,
            'agents.delete' => App\Kit\Agents\Telenow\DeleteAtTelenow::class,
            'agents.restore' => App\Kit\Agents\Telenow\RestoreAtTelenow::class,
            'agent_webhooks.create' => App\Kit\Agents\Telenow\CreateWebhookAtTelenow::class,
            'agent_webhooks.delete' => App\Kit\Agents\Telenow\DeleteWebhookAtTelenow::class,
            'consent.create' => App\Kit\Consent\Telenow\PushConsentAtTelenow::class,
            'consent.revoke' => App\Kit\Consent\Telenow\RevokeConsentAtTelenow::class,
            'consent_rule.update' => App\Kit\Consent\Telenow\SetConsentRuleAtTelenow::class,
            'dnc.create' => App\Kit\Dnc\Telenow\AddAtTelenow::class,
            'numbers.create' => App\Kit\Numbers\Telenow\BuyAtTelenow::class,
            'numbers.assign' => App\Kit\Numbers\Telenow\AssignAtTelenow::class,
            'numbers.delete' => App\Kit\Numbers\Telenow\ReleaseAtTelenow::class,
            'knowledge_bases.create' => App\Kit\Knowledge\Telenow\CreateBaseAtTelenow::class,
            'knowledge_bases.delete' => App\Kit\Knowledge\Telenow\DeleteBaseAtTelenow::class,
            'knowledge_documents.create' => App\Kit\Knowledge\Telenow\AddDocumentAtTelenow::class,
            'knowledge_documents.retry' => App\Kit\Knowledge\Telenow\RetryDocumentAtTelenow::class,
            'knowledge_documents.delete' => App\Kit\Knowledge\Telenow\DeleteDocumentAtTelenow::class,
            'agents.knowledge' => App\Kit\Knowledge\Telenow\SetAgentKnowledgeAtTelenow::class,
            'campaigns.create' => App\Kit\Campaigns\Telenow\CreateCampaignAtTelenow::class,
            'campaigns.settings' => App\Kit\Campaigns\Telenow\UpdateCampaignAtTelenow::class,
            'campaigns.targets' => App\Kit\Campaigns\Telenow\PushTargetsAtTelenow::class,
            'campaigns.start' => App\Kit\Campaigns\Telenow\StartCampaignAtTelenow::class,
            'campaigns.pause' => App\Kit\Campaigns\Telenow\PauseCampaignAtTelenow::class,
            'campaigns.cancel' => App\Kit\Campaigns\Telenow\CancelCampaignAtTelenow::class,
            'campaigns.delete' => App\Kit\Campaigns\Telenow\DeleteCampaignAtTelenow::class,
            'dnc.delete' => App\Kit\Dnc\Telenow\RemoveAtTelenow::class,
        ],
        // A write Telenow could not take yet is tried again after base × 2^(tries − 1) seconds, at most max, and
        // given up after max_attempts tries (Telenow's own reason is kept, with a retry button).
        'max_attempts' => (int) env('KIT_OUTBOX_MAX_ATTEMPTS', 8),
        'retry_base_seconds' => (int) env('KIT_OUTBOX_RETRY_BASE', 30),
        'retry_max_seconds' => (int) env('KIT_OUTBOX_RETRY_MAX', 3600),
        // How soon a write whose time has not come (App\Kit\Outbox\NotYet) is looked at again; it is not a try.
        'not_yet_seconds' => (int) env('KIT_OUTBOX_NOT_YET', 60),
        // How long a runner holds a write while it sends it (longer than a Telenow call can take).
        'lock_seconds' => (int) env('KIT_OUTBOX_LOCK', 120),
        // Writes one `kit:outbox` run sends at most.
        'batch' => (int) env('KIT_OUTBOX_BATCH', 50),
    ],

    'customers' => [
        // The name and role of the Telenow key each customer's sub-account is given (§5 C1: admin, so every
        // customer action is allowed).
        'key_name' => env('KIT_CUSTOMER_KEY_NAME', 'Customer key'),
        'key_role' => 'admin',
        // Telenow's limit on a sub-account's name (CreateSubAccountRequest.name).
        'name_max_length' => 160,
        // How long after closing was asked a call's event may still be landing: the close waits at least this long
        // (App\Kit\Customers\Settling), since closing revokes the key that reads the customer's calls.
        'close_settle_seconds' => (int) env('KIT_CLOSE_SETTLE_SECONDS', 120),
    ],

    'agents' => [
        // The columns that make an agent's provider stack. A new agent's stack comes from the operator's new-agent
        // defaults (setting `agents.defaults`, by column), then Telenow's recommendation (`getAgentDefaults`).
        'stack' => ['llm_provider', 'llm_model', 'stt_provider', 'tts_provider', 'tts_voice'],
        // The kit's own limits on what a customer types.
        'name_max_length' => 120,
        'description_max_length' => 500,
        'prompt_max_length' => (int) env('KIT_AGENT_PROMPT_MAX', 20000),
        'per_page' => (int) env('KIT_AGENTS_PER_PAGE', 50),
        // What a new agent starts with (App\Kit\Agents\AgentSettings). With none of these Telenow starts no call limit and no
        // silence hang-up, and the agent has no way to hang up, so a line left open would bill until someone hangs up.
        'new_agent_settings' => [
            'recording' => true,
            'maxDurationSecs' => (int) env('KIT_AGENT_MAX_DURATION_SECONDS', 600),
            'silenceHangupSecs' => (int) env('KIT_AGENT_SILENCE_HANGUP_SECONDS', 15),
            'silenceCheckinSecs' => (int) env('KIT_AGENT_SILENCE_CHECKIN_SECONDS', 10),
            'canEndCall' => true,
            // What Telenow's own builder starts a new agent with: without them it reads numbers badly and strays from its brief.
            'speechNormalization' => true,
            'smartMatching' => true,
            'scopeBoundaries' => true,
            'listeningSounds' => true,
            'contextMode' => 'summary',
            'contextTurns' => 10,
        ],
        // What a customer may set: Telenow keeps whatever it is sent, and its own builder holds these same bounds.
        'limits' => [
            'max_duration_seconds' => [30, 3600],
            'silence_seconds' => [5, 120],
            'line_max_length' => 500,
            'transfer_destinations_max' => 10,
            'transfer_numbers_max' => 10,
            'transfer_label_max_length' => 60,
            'analysis_fields_max' => 30,
            'analysis_key_max_length' => 60,
            'analysis_key_pattern' => '/^[a-z][a-z0-9_]*$/',
            'analysis_label_max_length' => 120,
            'analysis_min_duration_max' => 3600,
            'analysis_min_turns_max' => 100,
            'background_volume_percent' => [0, 100],
            'ring_sound_seconds' => [1, 30],
            'context_turns' => [1, 200],
            'caller_memory_days' => [1, 3650],
            'keypad_wait_ms' => [500, 15000],
            'keypad_digits' => [0, 32],
            'greeting_delay_seconds' => [0, 10],
            'ring_duration_seconds' => [5, 120],
            'tools_max' => 20,
            'tool_name_max_length' => 64,
            'tool_name_pattern' => '/^[A-Za-z0-9_-]+$/',
            'tool_description_max_length' => 1000,
            'tool_parameters_max' => 20,
            'tool_timeout_seconds' => [1, 30],
            'address_max_length' => 2048,
            'request_headers_max' => 20,
            'header_name_max_length' => 100,
            'request_body_max_length' => 20000,
            'key_max_length' => 4000,
            'variable_pattern' => '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',
            'lookups_max' => 5,
            'lookup_fills_max' => 20,
            'lookup_path_max_length' => 200,
            'lookup_path_pattern' => '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/',
            'handoff_agents_max' => 10,
            'webhooks_per_agent_max' => 5,
            'after_call_email_recipients_max' => 20,
        ],
        // What a customer's web address may be sent (App\Kit\Agents\AgentWebhooks): the events whose bodies name no platform.
        'webhook_events' => ['call.completed', 'call.analyzed'],
        'webhook_deliveries_shown' => (int) env('KIT_AGENT_WEBHOOK_DELIVERIES_SHOWN', 20),
        // Telenow's statuses after which it will not try again, and may be asked to.
        'webhook_finished_statuses' => ['delivered', 'dead'],
        // How old a call may be and still be emailed about (App\Kit\Agents\AfterCallEmail): one first read later than this is not news.
        // How Telenow's notes on an agent's versions read (recorded), and how its change rows name a tool.
        'version_notes' => ['created' => 'created', 'restored' => '/^restore of v(\d+)$/'],
        'version_tool_row' => 'Tool',
        'version_flow_row' => 'Flow',
        // Call steps (App\Kit\Agents\AgentFlow). Telenow's limits, and what a customer may build today: audio needs an audio library, and a step's own model, voice, knowledge and tools wait for their own part.
        'flow' => [
            'kinds' => ['conversation', 'extract', 'dtmf', 'static', 'router', 'tool', 'code', 'transfer', 'agent', 'end', 'note'],
            'instruction_kinds' => ['conversation', 'extract', 'dtmf', 'router', 'note'],
            'instructions_needed' => ['conversation', 'extract'],
            'line_kinds' => ['conversation', 'extract', 'dtmf', 'static', 'router', 'end'],
            'line_needed' => ['dtmf', 'static'],
            'continue_kinds' => ['conversation', 'extract', 'dtmf'],
            'capture_kinds' => ['conversation', 'extract', 'dtmf'],
            'no_exits_from' => ['end', 'note'],
            'exit_kinds' => ['always', 'equation', 'tool_result', 'fallback', 'dtmf', 'ai'],
            'capture_types' => ['string', 'number', 'boolean', 'enum'],
            // Telenow's other formats (phone, pincode, vehicle) read Indian numbers only.
            'capture_formats' => ['email', 'alphanumeric'],
            'code_language' => 'js',
            'schema' => 1,
            // What a save refuses, though the check only warns of them: a conditional section that does not pair up (features/prompt_sections/parse.rs).
            'refused_warnings' => ['section_unterminated_tag', 'section_stray_branch', 'section_elif_after_else', 'section_double_else', 'section_stray_close', 'section_unclosed', 'section_empty_condition', 'section_smart_quotes', 'section_bad_condition', 'section_parentheses', 'section_bang_operator', 'section_bad_operator', 'section_word_operator', 'section_no_operator'],
            'limits' => [
                'steps_max' => 100,
                'exits_max' => 300,
                'id_pattern' => '/^[A-Za-z0-9_-]{1,64}$/',
                'name_max_length' => 120,
                'capture_max' => 30,
                'capture_values_max' => 50,
                'examples_max' => 20,
                'condition_max_length' => 1000,
                'digit_pattern' => '/^[0-9*#]$/',
                'result_path_pattern' => '/^[A-Za-z0-9_.-]{1,200}$/',
                'results_max' => 30,
                'sends_max' => 50,
                'code_max_length' => 16000,
                'code_timeout_ms' => [100, 5000],
            ],
        ],
        // How long the page goes on saying what putting a version back left as it was.
        'restore_note_hours' => (int) env('KIT_AGENT_RESTORE_NOTE_HOURS', 24),
        // The parts of a call its email needs before it goes: what it says is the summary. Not the recording, which can read `pending` for hours.
        'after_call_email_waits_for' => ['analysis'],
        // How Telenow marks a call a machine picked up: `machine_start`, `machine_end_beep`, `machine_text_detect` and so on. Its own campaign worker reads it the same way.
        'machine_answer_prefix' => 'machine',
        'after_call_email_max_age_hours' => (int) env('KIT_AGENT_AFTER_CALL_EMAIL_MAX_AGE_HOURS', 24),
        // A customer's own API tools and look-ups (App\Kit\Agents\AgentTools, AgentLookups, AgentRequest), read from Telenow's source.
        'tool_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'lookup_methods' => ['GET', 'POST'],
        'tool_parameter_types' => ['string', 'number', 'integer', 'boolean'],
        'handoff_modes' => ['transfer', 'connect'],
        'template_filters' => ['digits', 'trim', 'lower', 'upper', 'urlencode'],
        // Names the platform keeps for itself, or a model would have two tools by.
        'reserved_tool_names' => ['transfer', 'transfer_to_human', 'end_call', 'agent', 'handoff_to_agent', 'navigate', 'dataset', 'followup', 'adjust_volume', 'set_language', 'opt_out', 'wait_for_caller', 'note_background_media', 'line_on_hold'],
        // Placeholders Telenow fills from the call, in a tool's request. The identifying ones are only there when the agent sends the caller to its tools.
        'caller_placeholders' => ['contact_number', 'contact_identifier', 'contact_channel', 'session_id', 'caller_number', 'caller_identifier', 'caller_channel'],
        'caller_placeholders_identifying' => ['contact_number', 'contact_identifier', 'caller_number', 'caller_identifier'],
        // Variables Telenow fills itself, which a look-up may never fill.
        'platform_variables' => ['contact_number', 'contact_identifier', 'contact_channel', 'session_id', 'caller_number', 'caller_identifier', 'caller_channel',
            'current_ist_datetime', 'current_ist_weekday', 'current_datetime', 'current_date', 'current_time', 'current_weekday', 'today_date', 'tomorrow_date', 'day_after_tomorrow_date', 'current_timezone', 'current_utc_offset'],
        // What Telenow treats as a secret, and how it shows one (utils/secrets.rs SENSITIVE_FIELDS, mask).
        'secret_fields' => ['apiKey', 'api_key', 'bearer', 'token', 'authToken', 'auth_token', 'accountSid', 'account_sid', 'clientSecret', 'client_secret', 'authorization', 'x-api-key',
            'password', 'passphrase', 'privateKey', 'private_key', 'accessKeyId', 'access_key_id', 'secretAccessKey', 'secret_access_key', 'sessionToken', 'session_token'],
        'secret_mask' => '••••',
        'secret_hint_min_chars' => 12,
        'secret_hint_chars' => 4,
        // Names that cannot be on the public internet, told from the text alone. Telenow checks again, resolving the name, when it sends.
        'private_host_endings' => ['localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp'],
        // Headers the HTTP client sets itself.
        'blocked_request_headers' => ['host', 'content-length', 'transfer-encoding', 'connection'],
        // The choices of how an agent speaks and listens (App\Kit\Agents\AgentBehaviour): Telenow's own values.
        'choices' => [
            'tones' => ['professional', 'professional_conversational'],
            'genders' => ['female', 'male', 'neutral'],
            'background_sounds' => ['office', 'call_center', 'coffee_shop', 'traffic', 'rain', 'phone_line', 'white', 'pink', 'brown'],
            'context_modes' => ['summary', 'window', 'full'],
            'keypad_keys' => ['#', '*', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        ],
        // How easily a caller can cut in, as Telenow's number from 0 to 1. Only 0 means it cannot be interrupted.
        'interruption_levels' => ['never' => 0, 'hard' => 0.15, 'normal' => 0.5, 'easy' => 0.85],
        // Agents `kit:agents` takes transfer off a run, after the operator turns transfers off.
        'transfer_sweep_per_run' => (int) env('KIT_AGENTS_TRANSFER_SWEEP_PER_RUN', 100),
        'transfer_modes' => ['serial', 'parallel'],
        'analysis_field_types' => ['string', 'number', 'boolean', 'date'],
        // What Telenow does when a setting is absent, so a form shows what will really happen.
        'telenow_silence_checkin_seconds' => 20,
        'telenow_analysis_min_duration' => 20,
        'telenow_analysis_min_turns' => 2,
        // What the agent's model is told of its built-in tools: Telenow's own wording, byte for byte.
        'tool_descriptions' => [
            'end_call' => 'End the call when the caller has clearly finished — they say goodbye, tell you they are done, or you asked whether there is anything else and they said no. Then say your closing line and call this in that SAME turn — do not wait for them to reply to your goodbye. Never end on the caller\'s answer to any other question you asked, and never while something you offered or promised is still open. If unsure, ask "Is there anything else I can help you with?" instead.',
            'transfer' => 'Transfer the caller to a human agent when they ask for a person or you cannot help with their request.',
            'adjust_volume' => 'Change how loudly you speak when the caller asks — louder, softer, or reset to the normal level.',
            'handoff' => 'Hand this call to another AI agent when the caller needs a different specialist or department. The other agent continues on the same call.',
        ],
    ],

    'events' => [
        // Telenow's events the kit takes, each with what applies it (App\Kit\Events\EventHandler). The kit's endpoint
        // subscribes to exactly these, and they are the manifest's events (contract/resources.yaml; a test holds them equal).
        'handlers' => [
            'call.completed' => App\Kit\Calls\Events\CallCompleted::class,
        ],
        // Where Telenow sends them (https, a public host). Empty: this kit's own APP_URL + /api/kit/telenow/events.
        'url' => env('KIT_EVENTS_URL'),
        // The label the kit's endpoint carries at Telenow, to find it among the operator's other endpoints.
        'source' => env('KIT_EVENTS_SOURCE', 'voice-kit'),
        // How often `kit:events` checks that Telenow still sends to the kit (it cannot tell the kit when it stops).
        'check_minutes' => (int) env('KIT_EVENTS_CHECK_MINUTES', 15),
        // Events applied per run; an event whose handler failed is tried again after base × 2^(tries − 1) seconds,
        // at most max, given up after max_attempts.
        'batch' => (int) env('KIT_EVENTS_BATCH', 100),
        'max_attempts' => (int) env('KIT_EVENTS_MAX_ATTEMPTS', 8),
        'retry_base_seconds' => (int) env('KIT_EVENTS_RETRY_BASE', 30),
        'retry_max_seconds' => (int) env('KIT_EVENTS_RETRY_MAX', 3600),
        'lock_seconds' => (int) env('KIT_EVENTS_LOCK', 120),
        // Applied events are forgotten after this many days (their raw bodies hold call data).
        'keep_days' => (int) env('KIT_EVENTS_KEEP_DAYS', 14),
    ],

    'calls' => [
        // Telenow's statuses of a call that is over (the ones its `call.completed` fires for, migration 0438).
        'ended_statuses' => ['ended', 'orphaned'],
        // The parts of a call the console shows. A call is read again until none of them is `pending` in Telenow's
        // `completeness`: after base × 2^(reads − 1) seconds, at most max, and at most max_reads times.
        'wait_for' => ['recording', 'transcript', 'analysis'],
        'reread_base_seconds' => (int) env('KIT_CALLS_REREAD_BASE', 60),
        'reread_max_seconds' => (int) env('KIT_CALLS_REREAD_MAX', 3600),
        'max_reads' => (int) env('KIT_CALLS_MAX_READS', 12),
        'reads_per_run' => (int) env('KIT_CALLS_READS_PER_RUN', 50),
        // The calls feed: each account read every feed_minutes, at most feed_accounts_per_run accounts and
        // feed_pages_per_run pages each per run (Telenow's rate limit is shared by the whole install).
        'feed_minutes' => (int) env('KIT_CALLS_FEED_MINUTES', 10),
        'feed_accounts_per_run' => (int) env('KIT_CALLS_FEED_ACCOUNTS', 20),
        'feed_pages_per_run' => (int) env('KIT_CALLS_FEED_PAGES', 5),
        'per_page' => (int) env('KIT_CALLS_PER_PAGE', 50),
        // A recording is passed to the browser this many bytes at a time.
        'stream_chunk_bytes' => 65536,
        // What members write on a call (App\Kit\Calls\CallWork), the kit's own: a note's length and how many one call keeps; a wrap-up's length, and the
        // suggestions the page offers, from the account's latest wrap-ups (so many read, so many offered); a follow-up's length, and a page of them.
        'note_max_length' => 2000,
        'notes_per_call_max' => 200,
        'wrapup_max_length' => 64,
        'wrapup_suggestions_read' => 500,
        'wrapup_suggestions' => 12,
        'followup_max_length' => 500,
        'followups_per_call_max' => 50,
        'followups_per_page' => (int) env('KIT_FOLLOWUPS_PER_PAGE', 50),
        // Calls on now (App\Kit\Calls\LiveCalls): Telenow is asked at most once an account in this many seconds, however many pages are open.
        'live_cache_seconds' => (int) env('KIT_LIVE_CALLS_CACHE', 15),
        // The calls on now listed by name, newest first: Telenow lists at most 200 at a time.
        'live_calls_listed' => (int) env('KIT_LIVE_CALLS_LISTED', 50),
        // Listening in (App\Kit\Calls\ListenIn): Telenow sends no sound to listen to for calls in a browser or a chat (its browser and chat paths have no listening tap; read from its code 11-04).
        'listen_unheard_channels' => ['web_call', 'web_chat', 'simulation', 'chat'],
        // How many of a call's listeners its page shows, newest first.
        'listens_shown' => 20,
    ],

    'wallet' => [
        // Charging calls (App\Kit\Wallet\CallCharges): Telenow rates a call minutes after it ends, so its cost is read
        // after base × 2^(reads − 1) seconds, at most max, up to max_reads times — a call never rated (an unanswered one)
        // is then left uncharged.
        'charge_base_seconds' => (int) env('KIT_CHARGE_BASE', 60),
        'charge_max_seconds' => (int) env('KIT_CHARGE_MAX', 3600),
        'charge_max_reads' => (int) env('KIT_CHARGE_MAX_READS', 10),
        'charges_per_run' => (int) env('KIT_CHARGES_PER_RUN', 50),
        // The spend limit at Telenow (App\Kit\Wallet\SpendLimit), set after every credit and charge and refreshed
        // every limit_refresh_minutes. The customer's recent calls give the money-to-cost ratio and the cost per minute
        // (the last ratio_calls charges); with none yet, the plan's prices and assumed_cost_per_minute_usd stand in.
        'limit_refresh_minutes' => (int) env('KIT_LIMIT_REFRESH_MINUTES', 30),
        'ratio_calls' => (int) env('KIT_LIMIT_RATIO_CALLS', 20),
        'assumed_cost_per_minute_usd' => env('KIT_LIMIT_COST_PER_MINUTE_USD', '0.10'),
        // The stop is not instant: Telenow ends live calls on its spend guard's tick (60 s, utils/spend_guard_worker.rs)
        // and rates a call ~30 + 150 s after it ends (utils/billing_settlement_worker.rs). The limit keeps room for that
        // many seconds of the customer's concurrent calls (its own concurrency limit, else assumed_concurrency).
        'overshoot_seconds' => (int) env('KIT_LIMIT_OVERSHOOT_SECONDS', 240),
        'assumed_concurrency' => (int) env('KIT_LIMIT_CONCURRENCY', 2),
        'entries_per_page' => (int) env('KIT_WALLET_PER_PAGE', 50),
        // The balance emails (App\Kit\Wallet\BalanceAlerts): a low-balance warning when the balance falls to the
        // account's level (its own, else the operator's default in Admin → Pricing), and an out-of-credit notice at
        // zero. Who in the account gets them and may choose its level (team roles).
        'alert_roles' => ['owner'],
    ],

    'numbers' => [
        // The numbers feed (a number Telenow changed or released itself, for non-payment say) and the ledger feed (its
        // rent and setup fees): each account read every feed_minutes, at most feed_accounts_per_run a run, the ledger
        // feed_pages_per_run pages each.
        'feed_minutes' => (int) env('KIT_NUMBERS_FEED_MINUTES', 15),
        'feed_accounts_per_run' => (int) env('KIT_NUMBERS_FEED_ACCOUNTS', 20),
        'feed_pages_per_run' => (int) env('KIT_NUMBERS_FEED_PAGES', 5),
        // The ledger entries that are number fees, charged to the wallet (a call's charge comes with the call).
        'fee_kinds' => ['rental', 'number_setup'],
        // Numbers for sale shown per search.
        'search_limit' => (int) env('KIT_NUMBERS_SEARCH_LIMIT', 20),
    ],

    'knowledge' => [
        // The kit's own limits on what a customer types.
        'name_max_length' => 120,
        'description_max_length' => 500,
        'title_max_length' => 200,
        // The most text one document takes (Telenow shows a document's first 500,000 characters).
        'text_max_length' => (int) env('KIT_KNOWLEDGE_TEXT_MAX', 500000),
        // Files Telenow takes (uploadKnowledgeFile: up to 20 MB, of these kinds); the console shows the smaller of this
        // and what this host lets PHP receive.
        'file_max_bytes' => 20 * 1024 * 1024,
        'file_types' => ['pdf', 'docx', 'txt', 'md', 'csv', 'json', 'xlsx'],
        // Where an upload waits until the outbox has sent it (then it is deleted): a filesystem disk.
        'upload_disk' => env('KIT_KNOWLEDGE_DISK', 'local'),
        // Telenow's document statuses: still being read, ready for agents, failed.
        'statuses' => [
            'working' => ['pending', 'embedding'],
            'ready' => ['embedded', 'indexed'],
            'failed' => ['failed'],
        ],
        // `kit:knowledge` reads each base's changes every feed_minutes, and a base with a document still being read
        // every run; at most feed_bases_per_run bases a run.
        'feed_minutes' => (int) env('KIT_KNOWLEDGE_FEED_MINUTES', 15),
        'feed_bases_per_run' => (int) env('KIT_KNOWLEDGE_FEED_BASES', 50),
    ],

    'campaigns' => [
        // The kit's own limits on what a customer sets; the rest are Telenow's (createCampaign): concurrency 1–50,
        // attempts 1–10. A new campaign starts from these.
        'name_max_length' => 120,
        'concurrency' => ['default' => 2, 'max' => 50],
        'max_attempts' => ['default' => 2, 'max' => 10],
        // How long before someone is tried again, in minutes; each attempt doubles it. Telenow takes 5 seconds to an hour and clamps anything else without saying.
        'retry_gap_minutes' => ['default' => 5, 'min' => 1, 'max' => 60],
        // Voicemail handling as Telenow names it.
        'machine_detection' => ['off', 'voicemail', 'hangup'],
        // A call list (CSV): the header names that hold the number (the first found wins), its size, and how many people
        // one import may add. Every other column becomes a variable the agent can use.
        'phone_columns' => ['phone_number', 'phone'],
        'import_max_bytes' => 2 * 1024 * 1024,
        'import_max_rows' => (int) env('KIT_CAMPAIGN_IMPORT_ROWS', 10000),
        // How many people one database insert writes (SQLite caps the values one statement may bind).
        'insert_batch' => 500,
        // How many people one push sends (Telenow takes up to 1,000), and the source name they carry.
        'push_batch' => 1000,
        'source' => 'kit',
        // Telenow's campaign statuses still in play (re-read), and finished ones (nothing more can be done).
        'active_statuses' => ['queued', 'running', 'paused'],
        'finished_statuses' => ['completed', 'cancelled'],
        // A call's outcome as the kit shows it. Telenow's is an open set that can hold a carrier's own words ("insufficient balance", recorded), so any other reads as `failed`.
        'answered_outcome' => 'answered',
        'unanswered_outcomes' => ['no-answer', 'busy', 'machine'],
        'failed_outcome' => 'failed',
        // Who can be tried again: Telenow's scopes (retryFailedTargets), and the people each takes back. Trying again costs money, so it is never automatic.
        'retry_scopes' => ['unanswered', 'all'],
        // Looking through a campaign's people, and how many rows one read of a CSV export takes.
        'people_filters' => ['waiting', 'answered', 'unanswered', 'failed'],
        'export_chunk' => 1000,
        // `kit:campaigns` re-reads each active campaign and its results every read_minutes, at most per_run a run.
        'read_minutes' => (int) env('KIT_CAMPAIGN_READ_MINUTES', 2),
        'per_run' => (int) env('KIT_CAMPAIGNS_PER_RUN', 50),
        'results_pages_per_run' => 5,
        'per_page' => (int) env('KIT_CAMPAIGNS_PER_PAGE', 50),
    ],

    'consent' => [
        // What Telenow takes (recordConsent). `import` is said by the kit for a file, never chosen by a customer. Written consent needs the exact words agreed to.
        'types' => ['express_written', 'express_oral', 'implied', 'existing_business_relationship'],
        'written_type' => 'express_written',
        'scopes' => ['marketing', 'transactional', 'service'],
        'default_scope' => 'marketing',
        'sources' => ['web_form', 'verbal_recorded', 'ivr_dtmf', 'paper'],
        'import_source' => 'import',
        'revoke_source' => 'customer',
        // Telenow matches a call to its consent by the number exactly, and +1415… and 1415… are two numbers to it (recorded), so a number here always has its + and country code.
        'phone_digits' => ['min' => 8, 'max' => 15],
        'phone_max_length' => 40,
        'text_max_length' => 5000,
        'version_max_length' => 60,
        'note_max_length' => 500,
        // A file: the header names that hold the number come from kit.campaigns.phone_columns; these hold each person's own dates. Its size and how many one file may add.
        'captured_column' => 'consent_date',
        'expires_column' => 'expires_on',
        'import_max_bytes' => 2 * 1024 * 1024,
        'import_max_rows' => (int) env('KIT_CONSENT_IMPORT_ROWS', 10000),
        'insert_batch' => 500,
        // Telenow has no bulk route: one record is one request. So many a go, then the outbox looks again; a request waits only when so few are ahead of it.
        'push_per_run' => (int) env('KIT_CONSENT_PUSH_PER_RUN', 100),
        'send_at_once_max' => 5,
        // `kit:consent`: accounts told of a change to the rule a run, and writes given up, tried again after so long.
        'rule_sweep_per_run' => (int) env('KIT_CONSENT_RULE_SWEEP', 50),
        'retry_given_up_minutes' => (int) env('KIT_CONSENT_RETRY_MINUTES', 15),
        'retry_given_up_per_run' => 20,
        'per_page' => (int) env('KIT_CONSENT_PER_PAGE', 50),
    ],

    'dnc' => [
        // The kit's own limits on what a customer types; a CSV import is at most Telenow's limit (importDnc: 2 MB).
        'phone_max_length' => 40,
        'reason_max_length' => 200,
        'import_max_bytes' => 2 * 1024 * 1024,
        // The DNC feed (Telenow's own entries — a caller's opt-out — and every removal): each account read every
        // feed_minutes, at most feed_accounts_per_run accounts and feed_pages_per_run pages each per run.
        'feed_minutes' => (int) env('KIT_DNC_FEED_MINUTES', 15),
        'feed_accounts_per_run' => (int) env('KIT_DNC_FEED_ACCOUNTS', 20),
        'feed_pages_per_run' => (int) env('KIT_DNC_FEED_PAGES', 5),
        'per_page' => (int) env('KIT_DNC_PER_PAGE', 50),
        // How many entries a search for one number reads: others can hold its digits, and are as true.
        'search_limit' => 20,
    ],

    'team' => [
        // A customer account's member roles, and those that may manage its team (invite, remove). The first role is
        // the one a new account's first invite suggests.
        'roles' => ['owner', 'member'],
        'managers' => ['owner'],
        // The role staff have while they look at an account's console (App\Kit\Console\ViewAs): read-only, never one
        // a member can be invited as.
        'viewer_role' => 'staff_viewer',
        // How long an invite link works.
        'invite_hours' => (int) env('KIT_INVITE_HOURS', 72),
        // Invite acceptances allowed per minute from one address.
        'accept_per_minute' => (int) env('KIT_INVITE_ACCEPT_PER_MINUTE', 10),
    ],

    'admin' => [
        // The staff roles that may use the admin panel (users.role). `owner` is the setup wizard's account: there is
        // always at least one (App\Kit\Staff\Staff).
        'staff_roles' => ['owner', 'admin', 'support'],
        // What each staff role may do: every admin route names one of these (App\Http\Middleware\EnsureAbility), and
        // the console shows only what the signed-in role may use. Ability → the roles that have it.
        'abilities' => [
            'reports' => ['owner', 'admin'],
            'health' => ['owner', 'admin', 'support'],
            'customers_read' => ['owner', 'admin', 'support'],
            'customers_suspend' => ['owner', 'admin', 'support'],
            'customers_manage' => ['owner', 'admin'],
            'customers_limits' => ['owner', 'admin'],
            'customers_close' => ['owner', 'admin'],
            'view_as' => ['owner', 'admin'],
            'money' => ['owner', 'admin'],
            'settings' => ['owner', 'admin'],
            'payment_gateways' => ['owner'],
            'staff' => ['owner'],
            'audit' => ['owner', 'admin'],
        ],
        'per_page' => (int) env('KIT_ADMIN_PER_PAGE', 50),
        // How long a staff invite link works.
        'invite_hours' => (int) env('KIT_STAFF_INVITE_HOURS', 72),
        // How long staff may look at the console as a customer (read-only) before it ends by itself.
        'view_as_minutes' => (int) env('KIT_VIEW_AS_MINUTES', 30),
    ],

    'reconcile' => [
        // The money check per customer (App\Kit\Health\Reconciliation, `kit:reconcile` every five minutes): each customer
        // again after every_minutes, at most accounts_per_run a run; a gap bigger than tolerance_usd is shown as drift.
        'every_minutes' => (int) env('KIT_RECONCILE_EVERY_MINUTES', 60),
        'accounts_per_run' => (int) env('KIT_RECONCILE_ACCOUNTS', 20),
        'tolerance_usd' => env('KIT_RECONCILE_TOLERANCE_USD', '0.01'),
        // A call in these charge states is not (yet) in the ledger: where drift comes from (App\Kit\Wallet\CallCharges).
        'uncharged_states' => ['pending', 'unrated', 'refused', 'not_charged', 'free'],
    ],

    'jobs' => [
        // Scheduled jobs (App\Kit\Health\JobRuns): one is late when it has not started grace_minutes after its schedule
        // said; the scheduler is not running when nothing has started for stale_minutes.
        'grace_minutes' => (int) env('KIT_JOBS_GRACE_MINUTES', 5),
        'stale_minutes' => (int) env('KIT_JOBS_STALE_MINUTES', 5),
    ],

    'health' => [
        // Admin → Voice platform (App\Kit\Health\HealthReport): events counted over recent_hours, and at most list rows
        // of each list (failed events, failed writes, drifting customers), each error cut at error_length characters.
        'recent_hours' => (int) env('KIT_HEALTH_RECENT_HOURS', 24),
        'list' => (int) env('KIT_HEALTH_LIST', 20),
        'error_length' => 500,
    ],

    'reports' => [
        // The admin dashboard and Cost vs price (App\Kit\Reports): the longest period one report covers, the ways Cost
        // vs price groups charges, and how many rows of the daily totals (App\Kit\Wallet\ChargeRollups) are read at
        // a time, and how many charges at a time when `kit:rollups-rebuild` writes them again.
        'max_days' => (int) env('KIT_REPORT_MAX_DAYS', 366),
        'groups' => ['customer', 'component', 'plan', 'day'],
        'read_chunk' => (int) env('KIT_REPORT_READ_CHUNK', 1000),
        'rebuild_chunk' => (int) env('KIT_REPORT_REBUILD_CHUNK', 500),
        // A call not ended this long after it started is not counted as live (its end may not have reached the kit).
        'live_call_hours' => (int) env('KIT_LIVE_CALL_HOURS', 4),
    ],

    'auth' => [
        // Sign-in attempts allowed per minute for one email from one address.
        'sign_in_per_minute' => (int) env('KIT_SIGN_IN_PER_MINUTE', 5),
        'password_min_length' => (int) env('KIT_PASSWORD_MIN_LENGTH', 12),
        // "Forgot your password?" requests allowed per minute for one email from one address, and from one address.
        'reset_requests_per_minute' => (int) env('KIT_RESET_REQUESTS_PER_MINUTE', 3),
        'reset_requests_per_minute_per_address' => (int) env('KIT_RESET_REQUESTS_PER_MINUTE_PER_ADDRESS', 10),
        // How long a password reset link works (once).
        'reset_minutes' => (int) env('KIT_RESET_MINUTES', 60),
    ],

    'signup' => [
        // Who may sign up (SELF_HOSTED_KIT_PLAN.md §4.5; a new install starts closed): closed (only invites), open
        // (anyone who verifies their address), approval (anyone, then staff approve each), domains (only addresses at
        // the operator's listed email domains). Every mode but closed needs email (verification).
        'modes' => ['closed', 'open', 'approval', 'domains'],
        // How customers get in: a password, a link or a code emailed each time, and/or a code sent to the phone they proved
        // (by text or WhatsApp: whichever senders Admin → Texts and WhatsApp has). Staff always use a password. A way that
        // is off is off for everyone who had it: a customer's password stops working when `password` is off.
        'methods' => ['password', 'magic_link', 'email_code', 'phone_code'],
        'default_methods' => ['password'],
        // The types an operator's own sign-up field may have, and how many such fields the form may carry.
        'field_types' => ['text', 'email', 'url', 'select'],
        'max_fields' => (int) env('KIT_SIGNUP_MAX_FIELDS', 10),
        'field_max_length' => 500,
        // A code emailed to confirm a sign-up or to sign in: its digits, how long it works, and how many tries it allows (it
        // works once). With the `email_code` way in on, a sign-up is confirmed by this code instead of a link.
        'email_code' => [
            'length' => (int) env('KIT_EMAIL_CODE_LENGTH', 6),
            'minutes' => (int) env('KIT_EMAIL_CODE_MINUTES', 10),
            'attempts' => (int) env('KIT_EMAIL_CODE_ATTEMPTS', 5),
        ],
        // How long a verification link works, and a sign-in link (each works once).
        'verify_hours' => (int) env('KIT_SIGNUP_VERIFY_HOURS', 48),
        'link_minutes' => (int) env('KIT_SIGN_IN_LINK_MINUTES', 15),
        // How long someone a sign-in provider vouched for, with no account, may take to finish signing up (their
        // session keeps them). A sign-up with a phone is confirmed by a code: kit.phone's length, minutes and tries.
        'provider_minutes' => (int) env('KIT_SIGNUP_PROVIDER_MINUTES', 30),
        // Sign-ups (and sign-in link requests) allowed per minute from one address, and for one email.
        'per_minute_per_address' => (int) env('KIT_SIGNUPS_PER_MINUTE_PER_ADDRESS', 5),
        'per_minute_per_email' => (int) env('KIT_SIGNUPS_PER_MINUTE_PER_EMAIL', 3),
        // An unverified sign-up is deleted this many days after its link expires (kit:signups); a refused one's
        // password is wiped when it is refused.
        'purge_days' => (int) env('KIT_SIGNUP_PURGE_DAYS', 7),
        // How many sign-ups Admin → Sign-ups lists besides those waiting for approval.
        'recent' => (int) env('KIT_SIGNUPS_RECENT', 50),
    ],

    'website' => [
        // The default theme's public pages (App\Kit\Website): the home page's sections, which the operator orders and
        // turns on or off, and the legal pages. Their words start from lang/<locale>/website.php.
        'home_sections' => ['features', 'pricing', 'cta'],
        'legal_pages' => ['terms', 'privacy'],
        'max_features' => (int) env('KIT_WEBSITE_MAX_FEATURES', 12),
        'max_plans' => (int) env('KIT_WEBSITE_MAX_PLANS', 6),
        'text_max_length' => 300,
        'long_text_max_length' => 20000,
        // A child theme: a folder under themes_path whose views/ hold copies of the default theme's templates at the
        // same paths; a copy wins (App\Kit\Website\Theme). Null: the default theme alone.
        'child_theme' => env('KIT_THEME') ?: null,
        'themes_path' => base_path('themes'),
    ],

    'console' => [
        // The console's own top-level pages (console/src/App.tsx): the site answers each with the built console.
        'paths' => ['setup', 'console', 'admin', 'reset', 'signup', 'signin'],
        // Where a release puts the built console (scripts/release.mjs builds it with this base).
        'build_dir' => 'console-app',
    ],

    'payments' => [
        // The ways customers can pay the operator (SELF_HOSTED_KIT_PLAN.md §4.6 a): id => driver class. Each driver
        // declares its own form and talks to its gateway (App\Kit\Payments\PaymentDriver), so a new gateway is a class
        // and a line here. A bank transfer (staff confirm the money arrived) is built in.
        'drivers' => [
            'manual' => App\Kit\Payments\Drivers\ManualDriver::class,
            'razorpay' => App\Kit\Payments\Drivers\RazorpayDriver::class,
            'stripe' => App\Kit\Payments\Drivers\StripeDriver::class,
            'paypal' => App\Kit\Payments\Drivers\PayPalDriver::class,
            'paystack' => App\Kit\Payments\Drivers\PaystackDriver::class,
            'flutterwave' => App\Kit\Payments\Drivers\FlutterwaveDriver::class,
            'tap' => App\Kit\Payments\Drivers\TapDriver::class,
        ],
        // How many gateways the operator may switch on, and how long a gateway's name may be.
        'max_gateways' => (int) env('KIT_PAYMENT_MAX_GATEWAYS', 20),
        'name_max_length' => 60,
        // How long a bank transfer's instructions may be.
        'instructions_max_length' => 2000,
        'manual' => [
            // The reference a customer quotes on a bank transfer, and how long staff have to confirm it before it is
            // given up (it can still be confirmed after).
            'reference_length' => 8,
            'expire_days' => (int) env('KIT_PAYMENT_MANUAL_EXPIRE_DAYS', 14),
        ],
        // Where each gateway's API is (a test copy points them at a stand-in), how long a call may take, and how soon an
        // open payment is asked about.
        'razorpay' => [
            'api_url' => env('KIT_RAZORPAY_API_URL', 'https://api.razorpay.com'),
            'timeout_seconds' => (int) env('KIT_RAZORPAY_TIMEOUT_SECONDS', 15),
            'check_after_minutes' => (int) env('KIT_RAZORPAY_CHECK_AFTER_MINUTES', 5),
            // Razorpay refuses a link that runs out sooner than 15 minutes from now.
            'min_link_minutes' => 16,
        ],
        'stripe' => [
            'api_url' => env('KIT_STRIPE_API_URL', 'https://api.stripe.com'),
            'api_version' => env('KIT_STRIPE_API_VERSION', '2026-10-28.endive'),
            'timeout_seconds' => (int) env('KIT_STRIPE_TIMEOUT_SECONDS', 15),
            'check_after_minutes' => (int) env('KIT_STRIPE_CHECK_AFTER_MINUTES', 5),
            // How old a webhook's signature may be, as Stripe's own libraries allow.
            'signature_tolerance_seconds' => (int) env('KIT_STRIPE_SIGNATURE_TOLERANCE_SECONDS', 300),
        ],
        'paypal' => [
            'api_urls' => ['test' => env('KIT_PAYPAL_API_URL_TEST', 'https://api-m.sandbox.paypal.com'), 'live' => env('KIT_PAYPAL_API_URL_LIVE', 'https://api-m.paypal.com')],
            'timeout_seconds' => (int) env('KIT_PAYPAL_TIMEOUT_SECONDS', 15),
            'check_after_minutes' => (int) env('KIT_PAYPAL_CHECK_AFTER_MINUTES', 5),
        ],
        'paystack' => [
            'api_url' => env('KIT_PAYSTACK_API_URL', 'https://api.paystack.co'),
            // Its table of currencies, read 11-03. Which of them an account gets depends on its country.
            'currencies' => ['NGN', 'USD', 'GHS', 'ZAR', 'KES', 'XOF'],
            'timeout_seconds' => (int) env('KIT_PAYSTACK_TIMEOUT_SECONDS', 15),
            'check_after_minutes' => (int) env('KIT_PAYSTACK_CHECK_AFTER_MINUTES', 5),
        ],
        'flutterwave' => [
            'api_url' => env('KIT_FLUTTERWAVE_API_URL', 'https://api.flutterwave.com'),
            'timeout_seconds' => (int) env('KIT_FLUTTERWAVE_TIMEOUT_SECONDS', 15),
            'check_after_minutes' => (int) env('KIT_FLUTTERWAVE_CHECK_AFTER_MINUTES', 5),
        ],
        'tap' => [
            'api_url' => env('KIT_TAP_API_URL', 'https://api.tap.company'),
            // The currencies its webhook page gives the places of, read 11-03. Which of them an account gets depends on its country.
            'currencies' => ['SAR', 'AED', 'KWD', 'BHD', 'QAR', 'OMR', 'EGP', 'JOD', 'USD', 'EUR', 'GBP'],
            'timeout_seconds' => (int) env('KIT_TAP_TIMEOUT_SECONDS', 15),
            'check_after_minutes' => (int) env('KIT_TAP_CHECK_AFTER_MINUTES', 5),
        ],
        // One refund of a payment at a time, however long its gateway takes to answer: longer than a driver's calls can last.
        'refund_lock_seconds' => (int) env('KIT_PAYMENT_REFUND_LOCK_SECONDS', 120),
        // A gateway's token is asked for again this long before it runs out.
        'token_margin_seconds' => (int) env('KIT_PAYMENT_TOKEN_MARGIN_SECONDS', 300),
        // An online payment still open is asked about (kit:payments) once it is older than its driver's
        // checkAfterMinutes, at most every check_every_minutes, up to checks_per_run a run; after expire_hours it is
        // given up (a verified payment arriving later still credits).
        'check_every_minutes' => (int) env('KIT_PAYMENT_CHECK_EVERY_MINUTES', 10),
        'checks_per_run' => (int) env('KIT_PAYMENT_CHECKS_PER_RUN', 50),
        'expire_hours' => (int) env('KIT_PAYMENT_EXPIRE_HOURS', 24),
        // What a dispute does to the customer (App\Kit\Payments\PaymentSettings), and the rule until the operator picks.
        'dispute_actions' => ['nothing', 'hold', 'suspend'],
        'dispute_action' => 'hold',
        // A customer cannot start a top-up while its account is paused for one of these reasons
        // (App\Kit\Console\ServicePause); being out of credit is when it needs one.
        'blocked_when' => ['account_suspended', 'service_paused'],
        // A currency's decimal places: these, else its ISO 4217 minor units as ICU knows them (ext-intl), else
        // default_decimals.
        'currency_decimals' => [],
        'default_decimals' => 2,
        // How long a gateway's events are kept (their bodies can name the payer).
        'events_keep_days' => (int) env('KIT_PAYMENT_EVENTS_KEEP_DAYS', 90),
        // How many payments a list shows (the console: the account's; admin: everyone's), how often the console asks
        // about a payment it is waiting for, and how much of a gateway's error staff see.
        'recent' => (int) env('KIT_PAYMENTS_RECENT', 50),
        'poll_seconds' => (int) env('KIT_PAYMENT_POLL_SECONDS', 5),
        'error_max_length' => 500,
        // Returns from a gateway allowed per minute from one address.
        'returns_per_minute' => (int) env('KIT_PAYMENT_RETURNS_PER_MINUTE', 30),
    ],

    'sms' => [
        // The ways the kit can send a text (SELF_HOSTED_KIT_PLAN.md §4.7): id => driver class. Each driver declares its
        // own form (App\Kit\Sms\SmsDriver), so a provider's own driver is a class and a line here. Built in: any
        // provider with an HTTP API, described by the operator (App\Kit\Sms\Drivers\HttpDriver), and WhatsApp through
        // Meta's Cloud API (App\Kit\Sms\Drivers\WhatsAppCloudDriver).
        'drivers' => [
            'http' => App\Kit\Sms\Drivers\HttpDriver::class,
            'whatsapp_cloud' => App\Kit\Sms\Drivers\WhatsAppCloudDriver::class,
        ],
        // How many senders the operator may line up: the first sends, the next ones are tried when it fails.
        'max_senders' => (int) env('KIT_SMS_MAX_SENDERS', 5),
        // How much of a sender's error is kept for the admin's list, and how many recent texts it lists.
        'error_max_length' => 500,
        'recent' => (int) env('KIT_SMS_RECENT', 50),
        'http' => [
            // The choices on the HTTP driver's form, the first of each being the default; `digits` sends the number
            // without its `+`.
            'methods' => ['POST', 'GET'],
            'formats' => ['json', 'form'],
            'number_formats' => ['e164', 'digits'],
            'default_to_param' => 'to',
            'default_text_param' => 'text',
            'timeout_seconds' => (int) env('KIT_SMS_HTTP_TIMEOUT', 15),
            // How much of a provider's answer a failure quotes.
            'answer_max_length' => 300,
        ],
        'whatsapp_cloud' => [
            // Meta's Graph API, and the version to call. The language a new sender's templates start with.
            'graph_url' => env('KIT_WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
            'graph_version' => env('KIT_WHATSAPP_GRAPH_VERSION', 'v26.0'),
            'default_language' => 'en_US',
            'timeout_seconds' => (int) env('KIT_WHATSAPP_TIMEOUT', 15),
        ],
    ],

    'phone' => [
        // A phone number as the kit keeps it: E.164 (a +, the country code, the number).
        'pattern' => '/^\+[1-9][0-9]{7,14}$/',
        // A one-time code: its digits, how long it works, and how many tries it allows (it works once).
        'code_length' => (int) env('KIT_PHONE_CODE_LENGTH', 6),
        'code_minutes' => (int) env('KIT_PHONE_CODE_MINUTES', 10),
        'code_attempts' => (int) env('KIT_PHONE_CODE_ATTEMPTS', 5),
        // Asking for codes, allowed per minute for one number and from one address; using them, from one address.
        'per_minute_per_number' => (int) env('KIT_PHONE_CODES_PER_MINUTE_PER_NUMBER', 3),
        'per_minute_per_address' => (int) env('KIT_PHONE_CODES_PER_MINUTE_PER_ADDRESS', 10),
    ],

    'agent_calls' => [
        // An agent calling one person (App\Kit\Agents\AgentCalls): what a member may give for the call. How a machine answering is handled, in Telenow's words
        // (initiateCall `machineDetection`; none leaves it to the agent's own settings).
        'machine_answers' => ['voicemail' => 'true', 'hang_up' => 'hangup'],
        'number_max_length' => 40,
        'variable_value_max_length' => 500,
        'opening_line_max_length' => 1000,
        // What Telenow keeps an agent's call to one person as (recorded): the kit's copy says the same until it is read.
        'kept_as' => ['direction' => 'outbound', 'channel' => 'telephony'],
        // Calls one member may ask for a minute: a page stuck trying again cannot place them one after another.
        'calls_per_minute' => (int) env('KIT_AGENT_CALLS_PER_MINUTE', 6),
    ],

    'stack_check' => [
        // Checking an agent's voice setup (App\Kit\Agents\StackCheck, Telenow's preflight): its parts in the order shown, how Telenow says whose problem a failure is and the
        // kit's words for each (lang agents.check.*), and checks one member may ask for a minute (Telenow takes one an account every 3 s, recorded).
        'parts' => ['llm', 'stt', 'tts'],
        // The part an agent on a realtime voice adds: Telenow's check probes only the parts above, whatever the engine (recorded, contract_k14realtime.py).
        'realtime_part' => 'realtime',
        'attributions' => ['customer_account', 'customer_config', 'provider_outage', 'platform', 'unknown'],
        'per_minute' => (int) env('KIT_STACK_CHECKS_PER_MINUTE', 6),
    ],

    'voice_stars' => [
        // Voices a member stars in an account (App\Kit\Voices\VoiceStars), at most so many (Telenow's own limit for its stars).
        'per_member' => (int) env('KIT_VOICE_STARS_PER_MEMBER', 500),
    ],

    'own_voices' => [
        // An account's own voices, cloned from a person who agreed (App\Kit\Voices\OwnVoices). Off until the operator allows it (Admin → Catalog), and then at most this many
        // clones an account unless the operator says otherwise; never more than Telenow's own limit for clones on its keys (recorded: "platform voice-clone limit reached (50)").
        'clones_per_account' => (int) env('KIT_CLONES_PER_ACCOUNT', 10),
        'platform_clone_limit' => 50,
        // Whose voice it may be: Telenow refuses "self" from a key (an API key cannot attest a voice is its creator's own, recorded), so the person is always named.
        'relations' => ['employee', 'voice_actor', 'customer', 'other'],
        // The release the member confirms the kit holds, sent to Telenow as `consent_text_version` and kept with who confirmed it. A new wording gets a new version.
        'release_version' => env('KIT_VOICE_RELEASE_VERSION', 'kit-voice-release-1'),
        // Days Telenow keeps the voiceprint before deleting it (`retention_days`; 0 keeps it until deleted). Unset: Telenow's own default.
        'retention_days' => env('KIT_VOICE_RETENTION_DAYS') === null ? null : (int) env('KIT_VOICE_RETENTION_DAYS'),
        // A sample (uploadVoiceSample): Telenow takes up to 25 MB that ffmpeg can read, at least 3 s of speech (recorded); the kit forwards it and keeps no copy.
        'sample_max_bytes' => 25 * 1024 * 1024,
        'sample_min_seconds' => 3,
        'sample_types' => ['wav', 'mp3', 'ogg', 'oga', 'opus', 'webm', 'm4a', 'flac', 'aac'],
        'name_max_length' => 120,
        'person_max_length' => 120,
        'language_max_length' => 16,
        'script_max_length' => 1000,
        // How Telenow names an own voice on an agent (`ttsVoice`), and how the kit names one to customers instead: never Telenow's id, nor the provider's.
        'telenow_prefix' => 'profile:',
        'kit_prefix' => 'own:',
        // Which offered voice providers can clone, as Telenow says for the account (checkVoiceCloning), read again after this long.
        'targets_cache_seconds' => (int) env('KIT_CLONE_TARGETS_CACHE_SECONDS', 300),
        // Changes one member may make a minute (a voice, a recording, a clone, a removal): a page stuck trying again cannot send recordings one after another.
        'writes_per_minute' => (int) env('KIT_OWN_VOICE_WRITES_PER_MINUTE', 20),
    ],

    'softphone' => [
        // Calls a member places from the browser (App\Kit\Softphone\BrowserCalls). Telenow's mode for one, and how it rates one: by these attributes, with
        // only these parts (recorded, contract_k9human.py); a plan that prices none of them cannot charge one, so the kit places none on it.
        'mode' => 'manual',
        'rated_as' => ['callType' => 'manual', 'direction' => 'outbound', 'channel' => 'softphone'],
        'rated_parts' => ['telephony'],
        // Telenow dials whatever it is given, words included (recorded), so only a number as App\Kit\Sms\PhoneNumber reads one reaches it; one typed is at most so long.
        'number_max_length' => 40,
        // Calls one member may place a minute: a page stuck trying again cannot place them one after another.
        'calls_per_minute' => (int) env('KIT_BROWSER_CALLS_PER_MINUTE', 6),
    ],

    'oidc' => [
        // Sign-in providers with a known issuer (App\Kit\Oidc\OidcProviders); any other OpenID Connect provider is
        // added by its issuer. Their names are in lang/<locale>/oidc.php.
        'presets' => [
            'google' => ['issuer' => 'https://accounts.google.com'],
            // Any work, school or personal Microsoft account. `common` serves every tenant, so its configuration names the
            // issuer with a {tenantid} that each token's `tid` fills. Microsoft states no email_verified and warns its `email`
            // may be neither correct nor stable, so an address counts as verified only for a personal account's tenant or
            // with the `xms_edov` claim (the operator adds it to their app; see lang oidc.preset_help).
            'microsoft' => [
                'issuer' => 'https://login.microsoftonline.com/common/v2.0',
                'tenant_issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0',
                'verified_email' => ['claim' => 'xms_edov', 'tenants' => ['9188040d-6c67-4c5b-b112-36a304b66dad']],
            ],
        ],
        // What the kit asks the provider for, and the ID token signatures it accepts (never `none` or a shared secret).
        'scopes' => 'openid email profile',
        'algorithms' => ['RS256'],
        'max_providers' => (int) env('KIT_OIDC_MAX_PROVIDERS', 5),
        'name_max_length' => 40,
        // How long a sign-in started at a provider may take to come back, and how many may be under way in one session.
        'state_minutes' => (int) env('KIT_OIDC_STATE_MINUTES', 10),
        'max_pending' => 5,
        // Clock skew allowed on an ID token, how long a provider's configuration and keys are kept, and the wait for it.
        'leeway_seconds' => 60,
        'cache_minutes' => (int) env('KIT_OIDC_CACHE_MINUTES', 60),
        'timeout_seconds' => (int) env('KIT_OIDC_TIMEOUT', 10),
        'error_max_length' => 300,
        // Starting or finishing a sign-in, allowed per minute from one address.
        'per_minute_per_address' => (int) env('KIT_OIDC_PER_MINUTE', 20),
    ],

    'captcha' => [
        // The bot checks an operator can pick (App\Kit\Captcha\Captcha): each widget is loaded with explicit rendering
        // and drawn by its browser global's render(), and a token is confirmed at its siteverify address — the same
        // protocol for all three, so a provider is data. Their names are in lang/<locale>/captcha.php.
        'providers' => [
            'turnstile' => [
                'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
                'global' => 'turnstile',
                'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            ],
            'hcaptcha' => [
                'script' => 'https://js.hcaptcha.com/1/api.js?render=explicit',
                'global' => 'hcaptcha',
                'verify_url' => 'https://api.hcaptcha.com/siteverify',
            ],
            'recaptcha' => [
                'script' => 'https://www.google.com/recaptcha/api.js?render=explicit',
                'global' => 'grecaptcha',
                'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
            ],
        ],
        // The forms a bot check can guard, and those it guards until the operator picks.
        'forms' => ['sign_up', 'sign_in', 'password_reset', 'sign_in_link', 'sign_in_email_code', 'sign_in_code'],
        'default_forms' => ['sign_up', 'password_reset', 'sign_in_link', 'sign_in_email_code', 'sign_in_code'],
        // The siteverify error codes that mean the secret itself is wrong (checked when the operator saves it).
        'bad_secret_codes' => ['invalid-input-secret', 'missing-input-secret'],
        'timeout_seconds' => (int) env('KIT_CAPTCHA_TIMEOUT', 10),
    ],

    'email' => [
        // The ways the kit can send email (SELF_HOSTED_KIT_PLAN.md §4.7): id => driver class. Each driver declares its
        // own form (App\Kit\Email\EmailDriver), so a new one is a class and a line here. SMTP is built in.
        'drivers' => [
            'smtp' => App\Kit\Email\Drivers\SmtpDriver::class,
        ],
        // How many senders the operator may line up: the first sends, the next ones are tried when it fails.
        'max_senders' => (int) env('KIT_EMAIL_MAX_SENDERS', 5),
        // An email no sender could send is tried again by `kit:emails` after each of these waits (minutes), then
        // given up.
        'retry_minutes' => [1, 5, 15, 60],
        // How many waiting emails one `kit:emails` run tries.
        'per_run' => (int) env('KIT_EMAILS_PER_RUN', 100),
        // How much of a sender's error is kept for the admin's list.
        'error_max_length' => 500,
        // How a date and time is written in an email (PHP date format; the kit's own time zone).
        'date_format' => 'j M Y, H:i T',
        // How many recent emails the admin page lists.
        'recent' => (int) env('KIT_EMAIL_RECENT', 50),
        // The operator's own wording of an email (Admin → Email → Templates, App\Kit\Email\EmailTexts): the most
        // paragraphs before or after its button, and how long its subject, a paragraph and the button's words may be.
        'template' => [
            'max_lines' => (int) env('KIT_EMAIL_TEMPLATE_MAX_LINES', 10),
            'subject_max_length' => 200,
            'line_max_length' => 2000,
            'action_max_length' => 60,
        ],
        'smtp' => [
            // The choices for a server's encryption, the first being the default: `tls` upgrades with STARTTLS and
            // refuses a server that cannot, `ssl` connects over TLS, `none` never encrypts (a local relay).
            'encryptions' => ['tls', 'ssl', 'none'],
            'default_port' => 587,
            'timeout_seconds' => (int) env('KIT_SMTP_TIMEOUT', 15),
        ],
    ],

];
