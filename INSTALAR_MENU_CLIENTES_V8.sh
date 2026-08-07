#!/usr/bin/env bash
set -euo pipefail

if [[ -f src/artisan ]]; then
    app_dir="src"
elif [[ -f artisan ]]; then
    app_dir="."
else
    echo "Error: ejecutá este instalador dentro de ~/proyectos/gei-web." >&2
    exit 1
fi

rm -f "$app_dir/app/Http/Controllers/ActualizarDbController.php"
rm -f "$app_dir/app/Http/Controllers/ClienteLiquidacionController.php"
rm -f "$app_dir/resources/views/actualizar-db/index.blade.php"
rm -f "$app_dir/tests/Feature/ActualizarDbTest.php"
rm -f "$app_dir/tests/Feature/ClientesTest.php"
rm -f "$app_dir/tests/Feature/LiquidacionesPropietariosTest.php"

echo "Menú y módulo Clientes actualizados correctamente."
