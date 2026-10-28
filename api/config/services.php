<?php

return [
    // External API (api/v1/*): per-key default rate limit; require_https null = production only
    'api' => ['rate_limit' => (int) env('API_RATE_LIMIT', 60), 'require_https' => env('API_REQUIRE_HTTPS')],


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

    // Push notifications for the mobile app (docs/FIREBASE-SETUP.md). Unset => push is silently disabled.
    'fcm' => ['credentials_path' => env('FCM_CREDENTIALS_PATH'), 'project_id' => env('FCM_PROJECT_ID')],
    // Instagram module (docs/INSTAGRAM.md). graph_base is shared with WhatsApp so one META_GRAPH_BASE points both at the local mock.
    'instagram' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
        'graph_base' => env('META_GRAPH_BASE', 'https://graph.facebook.com'),
        'dialog_base' => env('META_DIALOG_BASE', 'https://www.facebook.com'),
        'frontend_url' => env('IG_FRONTEND_URL'), // where the OAuth callback sends the browser back to (defaults to APP_URL)
        'media_base_url' => env('IG_MEDIA_BASE_URL'),      // public origin Instagram downloads media from (default APP_URL)
        'daily_limit' => (int) env('IG_DAILY_LIMIT', 100), // used only if Meta's content_publishing_limit doesn't say
    ],
    'whatsapp' => ['graph_version' => env('META_GRAPH_VERSION', 'v26.0'), 'graph_base' => env('META_GRAPH_BASE', 'https://graph.facebook.com')],
];
