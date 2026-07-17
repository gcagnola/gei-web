<?php

return [
    'importador' => [
        'python_bin' => env(
            'GEI_PYTHON_BIN',
            '/usr/bin/python3'
        ),
        'path' => env(
            'GEI_IMPORTADOR_PATH',
            '/opt/gei-liquidaciones-python'
        ),
        'base_dir' => env(
            'GEI_IMPORTADOR_BASE_DIR',
            env('GEI_IMPORTADOR_PATH', '/opt/gei-liquidaciones-python')
        ),
        'repositorio_id' => (int) env('GEI_IMPORTADOR_REPOSITORIO_ID', 123),
        'cobol_storage_path' => env(
            'GEI_COBOL_STORAGE_PATH',
            storage_path('app/private/liquidaciones/cobol')
        ),
        'timeout' => (int) env('GEI_IMPORTADOR_TIMEOUT', 120),
        'lock_store' => env('GEI_IMPORTADOR_LOCK_STORE', 'file'),
    ],
];
