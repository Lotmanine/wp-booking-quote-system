<?php

return [
    'api_key' => env('DISPATCH_API_KEY', ''),
    'wp_url' => rtrim((string) env('DISPATCH_WP_URL', ''), '/'),
    'hmac_secret' => env('DISPATCH_HMAC_SECRET', ''),
    'load_window_days' => 7,
    'allow_reset' => (bool) env('DISPATCH_ALLOW_RESET', false),
];
