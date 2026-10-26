<?php

return [
    'base_url' => env('PORTAL_URL', env('APP_URL')),
    'activation_hours' => (int) env('PORTAL_ACTIVATION_HOURS', 24),
    'password_reset_minutes' => (int) env('PORTAL_PASSWORD_RESET_MINUTES', 60),

    // Módulos aún no publicados: apagados por defecto en todos los entornos.
    // Para habilitarlos más adelante, cambiar las variables en .env y limpiar la caché.
    // Histórico inmutable contrastado con PostgreSQL. Cambiar solo luego de validar otro período.
    'cuenta_corriente' => [
        'periodo_base' => env('PORTAL_CUENTA_CORRIENTE_BASE_PERIODO', '202609'),
        'web_prueba' => (bool) env('PORTAL_CUENTA_CORRIENTE_WEB_PRUEBA', false),
    ],

    'funcionalidades' => [
        'recibos' => (bool) env('PORTAL_RECIBOS_ENABLED', false),
        // Cuentas COBOL: cerrado por defecto hasta conciliación financiera.
        'cuenta_corriente' => (bool) env('PORTAL_CUENTA_CORRIENTE_ENABLED', false),
        'transferencias' => (bool) env('PORTAL_TRANSFERENCIAS_ENABLED', false),
    ],
];
