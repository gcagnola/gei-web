<?php

return [
    'base_url' => env('PORTAL_URL', env('APP_URL')),
    'activation_hours' => (int) env('PORTAL_ACTIVATION_HOURS', 24),
    'password_reset_minutes' => (int) env('PORTAL_PASSWORD_RESET_MINUTES', 60),

    // Módulos aún no publicados: apagados por defecto en todos los entornos.
    // Para habilitarlos más adelante, cambiar las variables en .env y limpiar la caché.
    'funcionalidades' => [
        'recibos' => (bool) env('PORTAL_RECIBOS_ENABLED', false),
        'transferencias' => (bool) env('PORTAL_TRANSFERENCIAS_ENABLED', false),
    ],
];
