<?php

return [
    'enabled' => env('AI_SPAM_DETECTOR_ENABLED', true),
    // Classify and emit events, but do not reject submissions based on AI scores.
    'observe' => env('AI_SPAM_DETECTOR_OBSERVE', false),
    'methods' => ['POST'],
    'provider' => 'typesafe',
    'model' => 'jev-latest',
    'timeout' => 5,

    'thresholds' => [
        'spam' => 0.8,
        'prompt_injection' => 0.8,
    ],

    // Null keeps the built-in criteria. Append adds trusted application context.
    'criteria' => [
        'spam' => ['replace' => null, 'append' => null],
        'prompt_injection' => ['replace' => null, 'append' => null],
    ],

    // Named profiles override criteria per category, using the same replace/append keys.
    'profiles' => [],

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
