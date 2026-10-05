<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backend GeI Reloj Biométrico
    |--------------------------------------------------------------------------
    |
    | GeI-Web consulta el servicio Anviz desde Laravel. El navegador nunca
    | accede directamente al backend del reloj.
    |
    */
    'base_url' => rtrim((string) env('GEI_RELOJ_API_URL', 'http://192.168.10.50:18080'), '/'),
    'timeout' => (int) env('GEI_RELOJ_API_TIMEOUT', 20),
    'connect_timeout' => (int) env('GEI_RELOJ_CONNECT_TIMEOUT', 5),
];
