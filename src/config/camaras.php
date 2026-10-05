<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reproductor go2rtc
    |--------------------------------------------------------------------------
    |
    | URL que debe poder abrir el navegador del usuario. No lleva credenciales
    | del DVR: GeI-Web sólo conoce los nombres lógicos de los streams.
    |
    */
    'go2rtc_url' => env('GEI_CAMARAS_GO2RTC_URL', 'http://192.168.50.13:1984'),

    /*
    |--------------------------------------------------------------------------
    | Sitios
    |--------------------------------------------------------------------------
    |
    | Santa Fe queda activo ahora. Santo Tomé queda previsto pero inactivo
    | hasta que exista conectividad por VPN y se configuren sus streams.
    |
    */
    'sitios' => [
        'santa_fe' => [
            'nombre' => 'Santa Fe',
            'activo' => true,
            'camaras' => [
                ['id' => 'sf_01', 'nombre' => 'Cámara 1', 'sub' => 'sf_01_sub', 'main' => 'sf_01_main'],
                ['id' => 'sf_02', 'nombre' => 'Cámara 2', 'sub' => 'sf_02_sub', 'main' => 'sf_02_main'],
                ['id' => 'sf_03', 'nombre' => 'Cámara 3', 'sub' => 'sf_03_sub', 'main' => 'sf_03_main'],
                ['id' => 'sf_04', 'nombre' => 'Cámara 4', 'sub' => 'sf_04_sub', 'main' => 'sf_04_main'],
                ['id' => 'sf_05', 'nombre' => 'Cámara 5', 'sub' => 'sf_05_sub', 'main' => 'sf_05_main'],
                ['id' => 'sf_06', 'nombre' => 'Cámara 6', 'sub' => 'sf_06_sub', 'main' => 'sf_06_main'],
                ['id' => 'sf_07', 'nombre' => 'Cámara 7', 'sub' => 'sf_07_sub', 'main' => 'sf_07_main'],
                ['id' => 'sf_08', 'nombre' => 'Cámara 8', 'sub' => 'sf_08_sub', 'main' => 'sf_08_main'],
            ],
        ],

        'santo_tome' => [
            'nombre' => 'Santo Tomé',
            'activo' => true,
            'camaras' => [
                ['id' => 'st_01', 'nombre' => 'Cámara 1', 'sub' => 'st_01_sub', 'main' => 'st_01_main'],
                ['id' => 'st_02', 'nombre' => 'Cámara 2', 'sub' => 'st_02_sub', 'main' => 'st_02_main'],
                ['id' => 'st_03', 'nombre' => 'Cámara 3', 'sub' => 'st_03_sub', 'main' => 'st_03_main'],
                ['id' => 'st_04', 'nombre' => 'Cámara 4', 'sub' => 'st_04_sub', 'main' => 'st_04_main'],
                ['id' => 'st_05', 'nombre' => 'Cámara 5', 'sub' => 'st_05_sub', 'main' => 'st_05_main'],
                ['id' => 'st_06', 'nombre' => 'Cámara 6', 'sub' => 'st_06_sub', 'main' => 'st_06_main'],
                ['id' => 'st_07', 'nombre' => 'Cámara 7', 'sub' => 'st_07_sub', 'main' => 'st_07_main'],
                ['id' => 'st_08', 'nombre' => 'Cámara 8', 'sub' => 'st_08_sub', 'main' => 'st_08_main'],
            ],
        ],
    ],
];
