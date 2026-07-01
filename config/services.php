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

    /*
    | LINE Login / LIFF (member guard). The login channel id/secret are the
    | OAuth credentials of the LINE Login channel backing the LIFF app; the
    | LIFF id is consumed by the Vue front-end to initialise the LIFF SDK.
    | Verified server-side by App\Services\Line\LiffVerifyService.
    |
    | messaging_channel_access_token: the long-lived channel access token of the
    | SEPARATE LINE Messaging API channel (the shop's Official Account) used to
    | PUSH notifications to members. Distinct from the Login channel above — a
    | push only reaches a member who added the OA as a friend AND whose
    | line_user_id we stored. Consumed server-side by
    | App\Services\Line\LineMessagingService; empty = pushes are silently skipped.
    */
    'line' => [
        'login_channel_id' => env('LINE_LOGIN_CHANNEL_ID'),
        'login_channel_secret' => env('LINE_LOGIN_CHANNEL_SECRET'),
        'liff_id' => env('LINE_LIFF_ID'),
        'messaging_channel_access_token' => env('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'),
    ],

];
