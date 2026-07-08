<?php

return [
    'default' => env('MAIL_MAILER', 'smtp'),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('SMTP_HOST', env('MAIL_HOST', 'smtp.mailtrap.io')),
            'port' => env('SMTP_PORT', env('MAIL_PORT', 587)),
            'encryption' => env('SMTP_SECURE', env('MAIL_ENCRYPTION', 'tls')) === 'true' ? 'ssl' : null,
            'username' => env('SMTP_USER', env('MAIL_USERNAME')),
            'password' => env('SMTP_PASSWORD', env('MAIL_PASSWORD')),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
        'array' => [
            'transport' => 'array',
        ],
    ],
    'from' => [
        'address' => env('SMTP_USER', env('MAIL_FROM_ADDRESS', 'noreply@remixpost.local')),
        'name' => env('MAIL_FROM_NAME', 'remixpost'),
    ],
];
