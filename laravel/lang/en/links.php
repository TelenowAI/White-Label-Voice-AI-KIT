<?php

// Why a customer's link could not be read (App\Kit\Net\PublicLinks, via App\Kit\Campaigns\CampaignSources). Customers read these.
return [
    'shape' => 'That is not a web address.',
    'https' => 'Use a link that starts with https://.',
    'private' => 'That link points to a private address. Use a link anyone on the internet can open.',
    'port' => 'Use an ordinary https link (no port number).',
    'unresolved' => 'That address could not be found. Check the link.',
    'unreachable' => 'That link could not be reached just now.',
    'too_many_redirects' => 'That link sends the request on too many times.',
    'status' => 'That link answered with an error (:status). Check that anyone with the link can open it.',
    'web_page' => 'That link shows a web page, not a CSV file. For a spreadsheet, publish it to the web as CSV and use that link.',
    'too_big' => 'That file is too big: up to :max MB.',
];
