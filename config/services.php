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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY', env('RESEND_KEY')),
        'endpoint' => env('RESEND_ENDPOINT', 'https://api.resend.com/emails'),
        'timeout' => env('RESEND_TIMEOUT', 30),
        'idempotency_window_seconds' => env('RESEND_IDEMPOTENCY_WINDOW_SECONDS', 0),
    ],

    'backup' => [
        'encryption_key' => env('FIEA_BACKUP_ENCRYPTION_KEY'),
        'key_version' => env('FIEA_BACKUP_KEY_VERSION', 'v1'),
        'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
        'snapshot_backend' => env('FIEA_BACKUP_SNAPSHOT_BACKEND', 'auto'),
    ],

    'transactional_mail' => [
        'provider' => env('FIEA_MAIL_PROVIDER', 'resend'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
