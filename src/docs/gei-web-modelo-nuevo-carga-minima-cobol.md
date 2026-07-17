# Carga minima COBOL sobre modelo web_*

## Resumen

Se valido una carga minima y repetible de datos COBOL reales sobre el modelo nuevo `web_*`, usando exclusivamente la base temporal descartable `db_gei_web_migraciones_test`.

La prueba no toca `db_gei`, no modifica tablas heredadas y no carga historico completo. El seeder creado es experimental y no representa el importador definitivo.

Decision: **APTO_PARA_IMPORTADOR_PILOTO**.

## Entorno

- Rama Git: `feature/modelo-web-cobol`
- Base usada: `db_gei_web_migraciones_test`
- Base protegida: `db_gei` no fue usada.
- Contenedor Laravel: `gei-app`
- Fecha de prueba: 2026-07-17

Antes de ejecutar se confirmo:

```bash
git branch --show-current
git status --short
docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -e DB_DATABASE=db_gei_web_migraciones_test -w /var/www/html gei-app php -r 'echo "DB_DATABASE=".getenv("DB_DATABASE").PHP_EOL; if (getenv("DB_DATABASE") === "db_gei") { exit(2); }'
```

Resultado de base:

```text
DB_DATABASE=db_gei_web_migraciones_test
```

## Archivos COBOL usados

Origen:

```text
storage/app/private/liquidaciones/cobol/PROPIETAR.TXT
storage/app/private/liquidaciones/cobol/INQUILINO.TXT
storage/app/private/liquidaciones/cobol/CTACTEPRO.TXT
storage/app/private/liquidaciones/cobol/INQCTACTE.TXT
```

Muestra seleccionada:

```text
cuenta_propietario: 12020240300
cuenta_inquilino:  11032433700
periodo usado:     202309
```

Registros de origen usados por el seeder:

```text
PROPIETAR.TXT:  linea 192
INQUILINO.TXT:  linea 13834
CTACTEPRO.TXT:  3 movimientos del propietario
INQCTACTE.TXT:  3 movimientos del inquilino
```

## Seeder experimental

Archivo:

```text
database/seeders/WebModeloNuevoCargaMinimaCobolSeeder.php
```

Propiedades relevantes:

- aborta si la base no es `db_gei_web_migraciones_test`;
- usa cuentas fijas de muestra;
- usa `updateOrInsert` para idempotencia;
- inserta solo en tablas `web_*`;
- conserva trazabilidad de lote, archivo, registro, linea, clave e hash;
- carga una muestra minima, no historico completo.

## Migraciones aplicadas en temporal

Se aplicaron solamente las migraciones del modelo nuevo `web_*`:

```bash
php artisan migrate --path=database/migrations/2026_07_17_120000_create_web_modelo_nuevo_importacion_tables.php --force
php artisan migrate --path=database/migrations/2026_07_17_121000_create_web_modelo_nuevo_personas_roles_tables.php --force
php artisan migrate --path=database/migrations/2026_07_17_122000_create_web_modelo_nuevo_inmuebles_contratos_tables.php --force
php artisan migrate --path=database/migrations/2026_07_17_123000_create_web_modelo_nuevo_cuentas_movimientos_tables.php --force
php artisan migrate --path=database/migrations/2026_07_17_124000_create_web_modelo_nuevo_liquidaciones_tables.php --force
php artisan migrate --path=database/migrations/2026_07_17_125000_create_web_modelo_nuevo_pagos_facturas_auditoria_tables.php --force
php artisan migrate --path=database/migrations/2026_07_17_130000_create_web_modelo_nuevo_liquidacion_cobol_support_tables.php --force
php artisan migrate --path=database/migrations/2026_07_17_131000_add_cobol_liquidacion_support_fields_to_web_modelo_nuevo_tables.php --force
```

Todas las ejecuciones se hicieron con:

```text
DB_DATABASE=db_gei_web_migraciones_test
```

## Comando de carga

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -w /var/www/html gei-app \
  php artisan db:seed --class=WebModeloNuevoCargaMinimaCobolSeeder --force
```

## Conteos

Conteos iniciales, despues de migrar y antes del seeder:

```json
{
  "web_lotes_importacion": 0,
  "web_archivos_importados": 0,
  "web_registros_origen": 0,
  "web_personas": 0,
  "web_propietarios": 0,
  "web_inquilinos": 0,
  "web_inmuebles": 0,
  "web_contratos": 0,
  "web_contrato_inquilinos": 0,
  "web_contrato_propietarios": 0,
  "web_contrato_inmuebles": 0,
  "web_inmuebles_propietarios": 0,
  "web_cuentas_corrientes": 0,
  "web_conceptos_movimiento": 0,
  "web_movimientos_cuenta": 0
}
```

Conteos despues de la primera ejecucion:

```json
{
  "web_lotes_importacion": 1,
  "web_archivos_importados": 4,
  "web_registros_origen": 8,
  "web_personas": 2,
  "web_propietarios": 1,
  "web_inquilinos": 1,
  "web_inmuebles": 1,
  "web_contratos": 1,
  "web_contrato_inquilinos": 1,
  "web_contrato_propietarios": 1,
  "web_contrato_inmuebles": 1,
  "web_inmuebles_propietarios": 1,
  "web_cuentas_corrientes": 2,
  "web_conceptos_movimiento": 5,
  "web_movimientos_cuenta": 6
}
```

Conteos despues de la segunda ejecucion:

```json
{
  "web_lotes_importacion": 1,
  "web_archivos_importados": 4,
  "web_registros_origen": 8,
  "web_personas": 2,
  "web_propietarios": 1,
  "web_inquilinos": 1,
  "web_inmuebles": 1,
  "web_contratos": 1,
  "web_contrato_inquilinos": 1,
  "web_contrato_propietarios": 1,
  "web_contrato_inmuebles": 1,
  "web_inmuebles_propietarios": 1,
  "web_cuentas_corrientes": 2,
  "web_conceptos_movimiento": 5,
  "web_movimientos_cuenta": 6
}
```

Resultado de idempotencia: **la segunda ejecucion no duplico registros**.

## Integridad basica

Verificacion:

```json
{
  "movimientos_sin_cuenta": 0,
  "movimientos_propietario": 3,
  "movimientos_inquilino": 3,
  "registros_con_archivo": 8,
  "contratos_con_relaciones": 1
}
```

La integridad referencial tambien fue ejercitada por las FKs creadas por migracion.

## Errores y correcciones

Durante la prueba se detectaron dos ajustes necesarios en el seeder experimental:

- `web_registros_origen.estado` no permite `IMPORTADO`; se cambio a `GENERADO`.
- `web_archivos_importados.estado` no permite `GENERADO`; se mantuvo como `IMPORTADO`.

Tambien se corrigio una consulta manual de integridad que usaba `dominio_titular`; la columna real del modelo es `dominio`.

No se modificaron migraciones ni tablas heredadas.

## Limpieza final

Se ejecuto rollback controlado:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -w /var/www/html gei-app \
  php artisan migrate:rollback --step=8 --force
```

Verificacion final:

```text
web_tables=0
```

## Observaciones tecnicas

- La muestra confirma que el modelo acepta trazabilidad completa minima desde COBOL.
- La muestra confirma idempotencia basica con `updateOrInsert`.
- La muestra confirma que propietario, inquilino, inmueble, contrato, relaciones, cuentas, conceptos y movimientos pueden persistirse de forma consistente.
- El seeder no debe evolucionar a importador definitivo; su valor es validar el modelo y servir como prueba controlada.
- El importador piloto debe reemplazar cuentas fijas por seleccion y clasificacion incremental real.

## Estado final

- Base temporal limpia: si.
- `db_gei` real tocada: no.
- Tablas heredadas tocadas: no.
- Generador PDF modificado: no.
- Carga historica completa ejecutada: no.

Decision final: **APTO_PARA_IMPORTADOR_PILOTO**.
