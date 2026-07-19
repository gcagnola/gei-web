# Importador COBOL Piloto Bulk

## Objetivo

Validar que el importador piloto puede cargar el lote completo de los cuatro archivos COBOL principales en el modelo nuevo `web_*`, usando exclusivamente la base temporal `db_gei_web_migraciones_test`.

Esta prueba no habilita importacion productiva, no genera liquidaciones y no integra UI. El comando sigue abortando si la conexion apunta a `db_gei` o a cualquier base distinta de `db_gei_web_migraciones_test`.

## Archivos procesados

Base usada dentro del contenedor Laravel:

```bash
storage/app/private/liquidaciones/cobol
```

Archivos:

- `PROPIETAR.TXT`
- `INQUILINO.TXT`
- `CTACTEPRO.TXT`
- `INQCTACTE.TXT`

## Estrategia bulk

El modo anterior del piloto usaba `updateOrInsert` por registro. Era suficiente para muestras pequenas, pero no para el lote completo de aproximadamente 614 mil registros.

El modo bulk agrega:

- opcion `--modo=bulk`;
- opcion `--chunk-size=5000`;
- lectura de movimientos por streaming;
- inserciones masivas por chunks;
- `INSERT ... SELECT ... FROM (VALUES ...) WHERE NOT EXISTS` compatible con PostgreSQL 9.4;
- deduplicacion interna por clave funcional antes de insertar cada chunk;
- casts explicitos para `bigint`, `integer`, `date`, `numeric`, `jsonb`, `boolean` y `timestamp with time zone`;
- limite interno de chunk para evitar superar el maximo de parametros y memoria de PHP.

No se uso `ON CONFLICT` porque la base temporal corre PostgreSQL 9.4.

## Comandos ejecutados

Confirmacion de base:

```bash
docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -e DB_DATABASE=db_gei_web_migraciones_test -w /var/www/html gei-app php artisan db:show --json
```

Reinicio de base temporal:

```bash
docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -e DB_DATABASE=db_gei_web_migraciones_test -w /var/www/html gei-app php artisan migrate:rollback --step=19 --force
docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -e DB_DATABASE=db_gei_web_migraciones_test -w /var/www/html gei-app php artisan migrate --force
```

Dry-run completo:

```bash
docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -e DB_DATABASE=db_gei_web_migraciones_test -w /var/www/html gei-app php artisan gei:web-importar-cobol-piloto --modo=bulk --base-dir=storage/app/private/liquidaciones/cobol --sin-limite --chunk-size=5000 --dry-run
```

Carga completa:

```bash
docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -e DB_DATABASE=db_gei_web_migraciones_test -w /var/www/html gei-app php artisan gei:web-importar-cobol-piloto --modo=bulk --base-dir=storage/app/private/liquidaciones/cobol --sin-limite --chunk-size=5000
```

Segunda ejecucion idempotente:

```bash
docker exec -i --user "$(id -u):$(id -g)" -e HOME=/tmp -e DB_DATABASE=db_gei_web_migraciones_test -w /var/www/html gei-app php artisan gei:web-importar-cobol-piloto --modo=bulk --base-dir=storage/app/private/liquidaciones/cobol --sin-limite --chunk-size=5000
```

## Dry-run

Resultado:

| Tipo | Cantidad |
| --- | ---: |
| propietarios | 4082 |
| inquilinos | 16935 |
| movimientos propietario | 234837 |
| movimientos inquilino | 358606 |

Total candidato: 614460 registros.

Duracion: 0.689 segundos.
Memoria pico: 350.01 MB.

## Primera carga completa

Duracion: 624.883 segundos.
Memoria pico: 224 MB.
Errores: 0.

| Tabla | Registros |
| --- | ---: |
| web_lotes_importacion | 1 |
| web_archivos_importados | 4 |
| web_registros_origen | 614460 |
| web_personas | 21017 |
| web_propietarios | 4082 |
| web_inquilinos | 16935 |
| web_inmuebles | 10384 |
| web_contratos | 16935 |
| web_contrato_inquilinos | 16935 |
| web_contrato_propietarios | 16933 |
| web_contrato_inmuebles | 16935 |
| web_inmuebles_propietarios | 16929 |
| web_cuentas_corrientes | 21017 |
| web_conceptos_movimiento | 75 |
| web_movimientos_cuenta | 593443 |

Movimientos por dominio:

| Dominio | Registros |
| --- | ---: |
| INQUILINO | 358606 |
| PROPIETARIO | 234837 |

## Segunda ejecucion

La segunda ejecucion con los mismos parametros produjo delta cero en todas las tablas medidas.

Duracion: 539.324 segundos.
Memoria pico: 224 MB.
Errores: 0.

| Tabla | Delta segunda ejecucion |
| --- | ---: |
| web_lotes_importacion | 0 |
| web_archivos_importados | 0 |
| web_registros_origen | 0 |
| web_personas | 0 |
| web_propietarios | 0 |
| web_inquilinos | 0 |
| web_inmuebles | 0 |
| web_contratos | 0 |
| web_contrato_inquilinos | 0 |
| web_contrato_propietarios | 0 |
| web_contrato_inmuebles | 0 |
| web_inmuebles_propietarios | 0 |
| web_cuentas_corrientes | 0 |
| web_conceptos_movimiento | 0 |
| web_movimientos_cuenta | 0 |

## Integridad basica

| Control | Resultado |
| --- | ---: |
| movimientos_sin_cuenta | 0 |
| registros_origen_sin_archivo | 0 |
| propietarios_sin_persona | 0 |
| inquilinos_sin_persona | 0 |
| contratos_sin_inquilino | 0 |
| contratos_sin_inmueble | 0 |
| contratos_sin_propietario | 2 |
| duplicados archivo/linea en registros origen | 0 |
| duplicados por registro_origen en movimientos | 0 |
| duplicados dominio/cuenta en cuentas corrientes | 0 |

Los 2 contratos sin propietario no rompen FK porque la relacion es derivada de `INQUILINO.TXT` hacia propietarios cargados. Deben revisarse antes de usar el lote para liquidacion piloto.

## Errores corregidos durante la prueba

- PostgreSQL 9.4 no soporta `ON CONFLICT`: se reemplazo por `INSERT ... SELECT ... WHERE NOT EXISTS`.
- Se superaba el limite de parametros de PostgreSQL: se agrego chunk efectivo calculado por cantidad de columnas.
- PHP agotaba memoria al preparar sentencias grandes: se bajo el techo interno del chunk SQL.
- Los movimientos se mantenian completos en memoria: se cambio a lectura streaming por chunks.
- Faltaban casts explicitos para columnas numericas auxiliares: `penalidad`, `abonado`, `iva` y `no_gravado`.
- Habia duplicados dentro de un mismo chunk para entidades deducidas, especialmente inmuebles: se agrego deduplicacion por clave funcional antes de insertar.

## Limitaciones actuales

- La reejecucion idempotente evita duplicados, pero todavia reevalua todo el lote y demora cerca de 9 minutos.
- El piloto sigue siendo experimental; no es importador definitivo.
- No se generan liquidaciones.
- No se integró con UI.
- No se validan todavia reglas completas de negocio COBOL para liquidacion.
- Los 2 contratos sin propietario deben investigarse antes de generar liquidaciones piloto.

## Seguridad

- La prueba se ejecuto con `DB_DATABASE=db_gei_web_migraciones_test`.
- El comando aborta si la base es `db_gei`.
- El comando aborta si la base no es `db_gei_web_migraciones_test`.
- No se tocaron tablas heredadas.
- No se ejecuto nada contra `db_gei`.
- No se modifico el generador PDF.

## Decision

APTO_PARA_GENERAR_LIQUIDACION_PILOTO, con dos advertencias:

- revisar los 2 contratos sin propietario;
- optimizar en una etapa posterior la segunda ejecucion para saltar chunks completos ya importados.
