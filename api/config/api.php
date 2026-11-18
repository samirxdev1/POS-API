<?php

return [
    'version' => 'v1',
    'base_prefix' => '/api/v1',
    'default_per_page' => 20,
    'max_per_page' => 100,
    
    // Static test token for testing & development (without needing DB migration)
    'static_test_token' => env('API_STATIC_TEST_TOKEN', 'pos_test_token_2026_antigravity'),
    'test_business_id' => (int)env('API_TEST_BUSINESS_ID', 1),
    'test_user_id' => (int)env('API_TEST_USER_ID', 1),

    // Rate limiting: max requests per minute per token/IP
    'rate_limit' => [
        'enabled' => true,
        'max_requests' => 120, // per 60 seconds
        'window_seconds' => 60,
        'storage_path' => __DIR__ . '/../../storage/framework/cache/api_rate_limits',
    ],

    // CORS configuration
    'cors' => [
        'allowed_origins' => explode(',', env('API_CORS_ALLOWED_ORIGINS', '*')),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'X-Business-Id'],
        'allow_credentials' => false,
        'max_age' => 86400,
    ],

    // Security & Logging
    'logging' => [
        'enabled' => true,
        'exclude_endpoints' => ['/api/v1/ping'],
        'sensitive_fields' => ['password', 'token', 'secret', 'card_number', 'cvv'],
    ],
];

