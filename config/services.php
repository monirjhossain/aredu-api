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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'stream_token' => env('CLOUDFLARE_STREAM_TOKEN'),
        'webhook_secret' => env('CLOUDFLARE_STREAM_WEBHOOK_SECRET'),
        'customer_code' => env('CLOUDFLARE_STREAM_CUSTOMER_CODE'),
        'max_duration_seconds' => (int) env('CLOUDFLARE_STREAM_MAX_DURATION_SECONDS', 3600),
        'playback_ttl_seconds' => (int) env('CLOUDFLARE_STREAM_PLAYBACK_TTL_SECONDS', 3600),
    ],

    'sslcommerz' => [
        'store_id' => env('SSLCOMMERZ_STORE_ID'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
        'sandbox' => filter_var(env('SSLCOMMERZ_SANDBOX', true), FILTER_VALIDATE_BOOL),
    ],

    'bkash' => [
        'username' => env('BKASH_USERNAME'),
        'password' => env('BKASH_PASSWORD'),
        'app_key' => env('BKASH_APP_KEY'),
        'app_secret' => env('BKASH_APP_SECRET'),
        'sandbox' => filter_var(env('BKASH_SANDBOX', true), FILTER_VALIDATE_BOOL),
    ],

];
