<?php

use App\Models\PortalUsuario;
use App\Models\Usuario;

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'portal' => [
            'driver' => 'session',
            'provider' => 'portal_users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', Usuario::class),
        ],
        'portal_users' => [
            'driver' => 'eloquent',
            'model' => PortalUsuario::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'usuarios',
        // ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env(
                'AUTH_PASSWORD_RESET_TOKEN_TABLE',
                'password_reset_tokens'
            ),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];