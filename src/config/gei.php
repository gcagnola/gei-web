<?php

return [
    'importador' => [
        'python_bin' => env(
            'GEI_PYTHON_BIN',
            '/usr/bin/python3'
        ),
        'path' => env(
            'GEI_IMPORTADOR_PATH',
            base_path('python')
        ),
        'base_dir' => env(
            'GEI_IMPORTADOR_BASE_DIR',
            storage_path('app/private/liquidaciones')
        ),
        'repositorio_id' => (int) env('GEI_IMPORTADOR_REPOSITORIO_ID', 123),
        'cobol_storage_path' => env(
            'GEI_COBOL_STORAGE_PATH',
            storage_path('app/private/liquidaciones/cobol')
        ),
        'timeout' => (int) env('GEI_IMPORTADOR_TIMEOUT', 120),
        'lock_store' => env('GEI_IMPORTADOR_LOCK_STORE', 'file'),
    ],

    'exploracion' => [
        'script' => env(
            'GEI_EXPLORACION_SCRIPT',
            base_path('scripts/crear_db_staging_gei.sh')
        ),
        'host' => env('GEI_EXPLORACION_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('GEI_EXPLORACION_PORT', env('DB_PORT', '5432')),
        'database' => env('GEI_EXPLORACION_DATABASE', 'gei_exploracion'),
        'username' => env('GEI_EXPLORACION_USERNAME', env('DB_USERNAME', 'postgres')),
        'password' => env('GEI_EXPLORACION_PASSWORD', env('DB_PASSWORD', '')),
        'schema' => env('GEI_EXPLORACION_SCHEMA', 'cobol_staging'),
        'timeout' => (int) env('GEI_EXPLORACION_TIMEOUT', 900),
        'lock_store' => env('GEI_EXPLORACION_LOCK_STORE', 'file'),
    ],

    'liquidaciones_propietarios' => [
        'python' => env('GEI_LIQUIDACIONES_PYTHON', '/opt/gei-python/bin/python'),
        'script' => env(
            'GEI_LIQUIDACIONES_SCRIPT',
            base_path('python/liquidaciones_propietarios/procesar.py')
        ),
        'numero_inicial' => (int) env('GEI_LIQUIDACIONES_NUMERO_INICIAL', 25194),
        'timeout' => (int) env('GEI_LIQUIDACIONES_TIMEOUT', 1800),
        'lock_store' => env('GEI_LIQUIDACIONES_LOCK_STORE', 'file'),
    ],

    'impuestos_garantizados' => [
        'python' => env(
            'GEI_IMPUESTOS_GARANTIZADOS_PYTHON',
            env('GEI_LIQUIDACIONES_PYTHON', '/opt/gei-python/bin/python')
        ),
        'script' => env(
            'GEI_IMPUESTOS_GARANTIZADOS_SCRIPT',
            base_path('python/impuestos_garantizados/generar.py')
        ),
        'encoding' => env('GEI_IMPUESTOS_GARANTIZADOS_ENCODING', 'cp1252'),
        'timeout' => (int) env(
            'GEI_IMPUESTOS_GARANTIZADOS_TIMEOUT',
            env('GEI_LIQUIDACIONES_TIMEOUT', 1800)
        ),
    ],
];
