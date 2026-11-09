<?php

/**
 * Web chat (App\Kit\Chat): what the console and the admin are told, what a visitor's chat box is told, and the chat box's own words (`words`),
 * which the script on the customer's website shows as they are. Nothing here names the voice platform.
 */
return [
    'not_allowed' => 'Web chat is not offered on this platform yet.',
    'not_priced' => 'Your plan has no price for a chat yet, so a chat box cannot be turned on.',
    'agent_not_ready' => 'This agent is still being set up. Try again in a minute.',
    'websites_required' => 'Add at least one website for the chat box to show on.',
    'website_invalid' => 'Give a website by its address alone, like https://www.example.com.',
    'not_found' => 'This chat is not available.',
    'website_not_allowed' => 'This chat is not available on this website.',
    'no_conversation' => 'This conversation was not found.',
    'ended' => 'This conversation has ended.',
    'still_answering' => 'Still answering your last message.',
    'too_many_messages' => 'This conversation has reached its most messages. Start a new one.',
    'too_fast' => 'Messages are coming too fast. Wait a moment.',
    'unavailable' => 'The chat cannot answer right now. Try again soon.',
    'message_required' => 'Write a message first.',
    'message_too_long' => 'Your message is too long.',
    'fields' => [
        'enabled' => 'chat box',
        'websites' => 'websites',
        'websites.*' => 'website',
        'title' => 'title',
        'greeting' => 'greeting',
        'message' => 'message',
        'conversation' => 'conversation',
    ],
    'words' => [
        'open' => 'Chat with us',
        'close' => 'Close',
        'placeholder' => 'Write a message…',
        'send' => 'Send',
        'waiting' => 'Writing a reply…',
        'failed' => 'Your message was not sent. Try again.',
        'busy' => 'Still answering your last message.',
        'tooFast' => 'Messages are coming too fast. Wait a moment.',
        'ended' => 'This conversation has ended.',
        'newChat' => 'Start a new conversation',
        'endChat' => 'End conversation',
        'tooLong' => 'Your message is too long.',
        'unavailable' => 'The chat cannot answer right now. Try again soon.',
        'you' => 'You',
    ],
];
