<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'anthropic' => [
        // 'api' calls Claude; 'mock' returns a canned draft offline (local testing).
        'drafter' => env('ANTHROPIC_DRAFTER', 'api'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'max_output_tokens' => (int) env('ANTHROPIC_MAX_OUTPUT_TOKENS', 1500),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
