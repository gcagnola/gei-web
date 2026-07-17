# Validacion temporal de migraciones modelo nuevo web_*

## Resumen

Se validaron las migraciones Laravel borrador del modelo nuevo `web_*` en una
base PostgreSQL temporal/descartable.

No se ejecuto ninguna migracion contra `db_gei`.
No se tocaron tablas heredadas.
No se importaron archivos COBOL.
No se modifico el generador PDF.

Decision:

```text
APTO_PARA_PRUEBA_DE_CARGA
```

## Rama Git

Rama activa confirmada antes de trabajar:

```text
feature/modelo-web-cobol
```

Estado inicial:

```text
git status --short
```

Sin cambios al inicio de la validacion.

## Configuracion revisada

La configuracion normal de `.env` apunta a:

```text
DB_CONNECTION=pgsql
DB_HOST=192.168.50.20
DB_PORT=5432
DB_DATABASE=db_gei
DB_USERNAME=postgres
```

No se mostro ni registro `DB_PASSWORD`.

Para la validacion se uso override explicito:

```text
DB_DATABASE=db_gei_web_migraciones_test
```

Tambien se verifico que no existiera cache de configuracion:

```text
config_cache=absent
```

## Base temporal usada

Base:

```text
db_gei_web_migraciones_test
```

Creacion/verificacion:

```text
CREATED db_gei_web_migraciones_test
host=192.168.50.20 port=5432 database=db_gei_web_migraciones_test user=postgres
```

## Confirmacion de seguridad antes de migrar

Antes de ejecutar migraciones se confirmo por comando:

```text
DB_DATABASE=db_gei_web_migraciones_test
```

Condicion de abort implementada en los comandos:

```text
si DB_DATABASE == db_gei, abortar
```

## Migraciones ejecutadas

Todas se ejecutaron con:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -w /var/www/html \
  gei-app php artisan migrate --path=<migration> --force
```

Orden ejecutado:

1. `database/migrations/2026_07_17_120000_create_web_modelo_nuevo_importacion_tables.php`
2. `database/migrations/2026_07_17_121000_create_web_modelo_nuevo_personas_roles_tables.php`
3. `database/migrations/2026_07_17_122000_create_web_modelo_nuevo_inmuebles_contratos_tables.php`
4. `database/migrations/2026_07_17_123000_create_web_modelo_nuevo_cuentas_movimientos_tables.php`
5. `database/migrations/2026_07_17_124000_create_web_modelo_nuevo_liquidaciones_tables.php`
6. `database/migrations/2026_07_17_125000_create_web_modelo_nuevo_pagos_facturas_auditoria_tables.php`
7. `database/migrations/2026_07_17_130000_create_web_modelo_nuevo_liquidacion_cobol_support_tables.php`
8. `database/migrations/2026_07_17_131000_add_cobol_liquidacion_support_fields_to_web_modelo_nuevo_tables.php`

Resultado:

```text
8 migraciones aplicadas correctamente
```

## Verificacion posterior a migrate

Consulta sobre `db_gei_web_migraciones_test`:

```json
{
  "database": "db_gei_web_migraciones_test",
  "web_tables": 29,
  "foreign_keys": 107,
  "checks": 309,
  "indexes": 108,
  "migrations": 8
}
```

Tablas `web_*` creadas:

```text
web_archivos_importados
web_auditoria_procesos
web_conceptos_movimiento
web_contrato_inmuebles
web_contrato_inquilinos
web_contrato_propietarios
web_contratos
web_correlativos
web_corridas_liquidacion
web_cotizaciones
web_cuentas_corrientes
web_facturas
web_inmuebles
web_inmuebles_propietarios
web_inquilinos
web_liquidaciones_impuestos_servicios
web_liquidaciones_movimientos
web_liquidaciones_pdfs
web_liquidaciones_propietarios
web_liquidaciones_propietarios_items
web_lotes_importacion
web_monedas
web_movimientos_cuenta
web_ordenes_no_liquidar
web_pagos
web_periodos
web_personas
web_propietarios
web_registros_origen
```

## Rollback controlado

Antes del rollback se confirmo nuevamente:

```text
DB_DATABASE=db_gei_web_migraciones_test
```

Comando ejecutado:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -w /var/www/html \
  gei-app php artisan migrate:rollback --step=8 --force
```

Resultado:

```text
8 migraciones revertidas correctamente
```

Orden de rollback observado:

1. `2026_07_17_131000_add_cobol_liquidacion_support_fields_to_web_modelo_nuevo_tables`
2. `2026_07_17_130000_create_web_modelo_nuevo_liquidacion_cobol_support_tables`
3. `2026_07_17_125000_create_web_modelo_nuevo_pagos_facturas_auditoria_tables`
4. `2026_07_17_124000_create_web_modelo_nuevo_liquidaciones_tables`
5. `2026_07_17_123000_create_web_modelo_nuevo_cuentas_movimientos_tables`
6. `2026_07_17_122000_create_web_modelo_nuevo_inmuebles_contratos_tables`
7. `2026_07_17_121000_create_web_modelo_nuevo_personas_roles_tables`
8. `2026_07_17_120000_create_web_modelo_nuevo_importacion_tables`

## Verificacion posterior a rollback

Consulta sobre `db_gei_web_migraciones_test`:

```json
{
  "database": "db_gei_web_migraciones_test",
  "web_tables_after_rollback": 0,
  "remaining_model_migrations": 0
}
```

## Errores encontrados

No hubo errores de migracion ni de rollback.

Observacion operativa:

- El host no tiene `php` disponible para `php -l`.
- La sintaxis PHP de las migraciones nuevas se habia validado previamente dentro
  del contenedor `gei-app`.

## Correcciones realizadas

No hubo correcciones de migraciones durante esta validacion.

## Estado final

```text
MIGRATE_OK
ROLLBACK_OK
WEB_TABLES_AFTER_ROLLBACK=0
DB_GEI_NO_TOCADA
```

Decision:

```text
APTO_PARA_PRUEBA_DE_CARGA
```

## Pendientes antes de aplicar fuera de una base descartable

1. Ejecutar una prueba de carga COBOL inicial contra una base descartable.
2. Medir volumen e indices con datos reales.
3. Revisar si conviene preservar la base temporal o recrearla por corrida.
4. Aprobar explicitamente cualquier ejecucion contra ambientes compartidos.

