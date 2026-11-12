<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'bachs' => [
        'key' => env('BACHS_API_KEY', env('BACHS_API_SANDBOX_KEY')),
        'base_url' => env('BACHS_BASE_URL', 'https://sandbox-api.bachs.io'),
        'webhook_secret' => env('BACHS_WEBHOOK_SECRET'),
        'currency' => 'NGN',
        // Bachs refuses localhost/private addresses for checkout return URLs, so in local
        // development set this to a public (e.g. tunnel) URL. Defaults to this app's wallet page.
        'return_url' => env('BACHS_RETURN_URL'),
    ],

];
