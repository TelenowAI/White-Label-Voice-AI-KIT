<?php

// A customer's knowledge (App\Kit\Knowledge, KnowledgeController). Customers read these: nothing names the platform, a
// vendor, or Telenow's own message (which names the vendor's address).
return [
    'fields' => [
        'name' => 'Name',
        'description' => 'Description',
        'title' => 'Title',
        'text' => 'Text',
        'file' => 'File',
        'url' => 'Web page',
        'knowledgeBaseIds' => 'Knowledge',
    ],
    'not_found' => 'There is no such knowledge base.',
    'document_not_found' => 'There is no such document.',
    'account_not_ready' => 'Your account is still being set up. Try again in a moment.',
    'not_ready' => 'This knowledge base is still being saved. Try again in a moment.',
    'will_retry' => 'Not saved yet. It will be tried again shortly.',
    'not_done' => 'This could not be done. Try again, or ask support.',
    'cancelled' => 'Deleted before it was saved.',
    'text_refused' => 'This text could not be added.',
    'page_refused' => 'That page could not be read. Check that it is public and its address starts with https://.',
    'file_refused' => 'That file could not be read. Use :types, up to :max MB.',
    'file_too_big' => 'That file is too big: up to :max MB.',
    'not_read' => 'This document could not be read for your agents. Try again, or ask support.',
    'nothing_to_retry' => 'Nothing failed for this document.',
    'https_only' => 'Use a page address that starts with https://.',
    'unknown_base' => 'Choose your own knowledge bases.',
];
