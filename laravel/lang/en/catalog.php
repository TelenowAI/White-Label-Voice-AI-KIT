<?php

// The catalog (App\Kit\Catalog): the operator's words in Admin → Catalog (they may name the platform), and the words a
// customer reads when a choice is refused (they never do).
return [
    'no_key' => 'Connect the voice platform first (Admin → Telenow).',
    'refresh_failed' => 'The catalog could not be read just now. Try again shortly.',
    'voices_unread' => 'These voice lists could not be read, so the last ones stay until the next try: :names.',
    'realtime_unread' => 'The realtime voices could not be read, so the last ones stay until the next try.',
    'realtime_off' => 'Realtime voices are not available on the service right now, so calls would use the voice pipeline. Choose the voice pipeline.',
    'realtime_not_a_choice' => 'Choose a realtime voice provider and model, or none.',
    'not_copied' => 'The catalog has not been read yet. Refresh it first.',
    'unknown' => 'There is no such option in the catalog.',
    'names_platform' => 'This name carries ":platform", which customers never see. Give it a name of your own.',
    'name_max' => 'Names are at most :max characters.',
    'default_not_offered' => 'A default must be one of the options offered.',
    'not_offered' => 'Choose one of the options offered.',
    'settings_not_a_map' => 'Send the settings by name.',
    'not_a_setting' => 'This is not one of the settings offered for this provider.',
    'setting_not_an_option' => 'Choose one of the options offered.',
    'setting_out_of_range' => 'Give a number from :min to :max.',
    'setting_not_a_switch' => 'This setting is on or off.',
    'setting_too_long' => 'This is at most :max characters.',
    'option_numbered' => 'Option :n',
    'open_models_unread' => 'OpenRouter\'s model list could not be read just now. Try again shortly.',
    'open_model_price_unread' => 'The price could not be read just now. Try again shortly.',
    'open_models_not_here' => 'Models can be added only to the provider that offers its whole list, by model id.',
    'open_models_max' => 'At most :max models can be added.',
    'open_model_unknown' => 'This is not a model in OpenRouter\'s list.',
    'open_model_in_catalog' => 'This model is in the catalog already: choose it there.',
    'processing_country_no_account' => 'This customer has no account on the voice platform yet.',
    'processing_country_unread' => 'The processing country could not be read just now. Try again shortly.',
    'not_for_model' => 'This voice is not one of the chosen model\'s voices. Choose another voice or model.',
];
