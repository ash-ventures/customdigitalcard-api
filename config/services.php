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

    'billplz' => [
        'api_key' => env('BILLPLZ_API_KEY'),
        'collection_id' => env('BILLPLZ_COLLECTION_ID'),
        'x_signature_key' => env('BILLPLZ_X_SIGNATURE_KEY'),
        'base_url' => env('BILLPLZ_BASE_URL', 'https://www.billplz-sandbox.com/api/v3'),
        'callback_url' => env('BILLPLZ_CALLBACK_URL', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/v1/payments/billplz/callback'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'chip' => [
        'api_key' => env('CHIP_API_KEY'),
        'brand_id' => env('CHIP_BRAND_ID'),
        'base_url' => env('CHIP_BASE_URL', 'https://gate.chip-in.asia/api/v1'),
        'success_callback_url' => env('CHIP_SUCCESS_CALLBACK_URL'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'payments' => [
        'provider' => env('PAYMENT_PROVIDER', 'chip'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
