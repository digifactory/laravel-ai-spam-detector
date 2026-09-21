<?php

return [
    'enabled' => env('AI_SPAM_DETECTOR_ENABLED', true),
    'methods' => ['POST'],
    'provider' => 'typesafe',
    'model' => 'jev-latest',
    'timeout' => 5,

    'thresholds' => [
        'spam' => 0.8,
        'prompt_injection' => 0.8,
    ],

    // Field names are excluded case-insensitively at every nesting level.
    'except_fields' => [
        'password', 'password_confirmation', 'current_password',
        '_token', '_method', 'token', 'access_token', 'refresh_token',
        'api_key', 'secret',
    ],
    'except_headers' => [
        'authorization', 'proxy-authorization', 'cookie',
        'x-csrf-token', 'x-xsrf-token', 'x-api-key',
    ],
];
