# Verificacion Bulk COBOL en PostgreSQL 17

## Resumen

Se ejecuto una verificacion complementaria del importador COBOL piloto bulk contra una base temporal en PostgreSQL 17.

La prueba confirma que el modo bulk carga el lote completo, conserva trazabilidad e idempotencia, y no requiere cambios funcionales inmediatos para operar sobre PostgreSQL 17.

Decision: APTO_PARA_GENERAR_LIQUIDACION_PILOTO.

## Conexion Validada

Conexion usada desde Laravel mediante variables de entorno, sin modificar `.env`:

- Host: `192.168.50.20`
- Puerto: `5430`
- Base: `db_gei_web_migraciones_test`
- Usuario: `postgres`
- Password: provisto por variable de entorno, no documentado.

Consulta de validacion:

```sql
select version(), current_database();
```

Resultado:

```text
PostgreSQL 17.10 (Debian 17.10-1.pgdg13+1)
current_database=db_gei_web_migraciones_test
```

Se confirmo:

- la version contiene `PostgreSQL 17`;
- la base no es `db_gei`;
- la conexion no apunta a PostgreSQL 9.4.

## Comandos Ejecutados

Estado Git inicial:

```bash
git status --short
git branch --show-current
```

Validacion de conexion:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_HOST=192.168.50.20 \
  -e DB_PORT=5430 \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -e DB_USERNAME=postgres \
  -e DB_PASSWORD="<variable de entorno>" \
  -w /var/www/html gei-app \
  php artisan tinker --execute='select version/current_database'
```

Migraciones en base temporal:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_HOST=192.168.50.20 \
  -e DB_PORT=5430 \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -e DB_USERNAME=postgres \
  -e DB_PASSWORD="<variable de entorno>" \
  -w /var/www/html gei-app \
  php artisan migrate --force
```

Dry-run completo:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_HOST=192.168.50.20 \
  -e DB_PORT=5430 \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -e DB_USERNAME=postgres \
  -e DB_PASSWORD="<variable de entorno>" \
  -w /var/www/html gei-app \
  php artisan gei:web-importar-cobol-piloto \
  --modo=bulk \
  --base-dir=storage/app/private/liquidaciones/cobol \
  --sin-limite \
  --chunk-size=5000 \
  --dry-run
```

Primera carga completa:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_HOST=192.168.50.20 \
  -e DB_PORT=5430 \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -e DB_USERNAME=postgres \
  -e DB_PASSWORD="<variable de entorno>" \
  -w /var/www/html gei-app \
  php artisan gei:web-importar-cobol-piloto \
  --modo=bulk \
  --base-dir=storage/app/private/liquidaciones/cobol \
  --sin-limite \
  --chunk-size=5000
```

Segunda carga completa:

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_HOST=192.168.50.20 \
  -e DB_PORT=5430 \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -e DB_USERNAME=postgres \
  -e DB_PASSWORD="<variable de entorno>" \
  -w /var/www/html gei-app \
  php artisan gei:web-importar-cobol-piloto \
  --modo=bulk \
  --base-dir=storage/app/private/liquidaciones/cobol \
  --sin-limite \
  --chunk-size=5000
```

## Dry-Run

| Tipo | Cantidad |
| --- | ---: |
| propietarios | 4082 |
| inquilinos | 16935 |
| movimientos_propietario | 234837 |
| movimientos_inquilino | 358606 |

Total candidato: 614460 registros.

Metricas:

- Duracion: 0.579 segundos.
- Memoria pico: 36 MB.
- Escritura: no.
- Errores: 0.

## Primera Carga Completa

Metricas:

- Duracion: 602.334 segundos.
- Memoria pico: 226 MB.
- Errores: 0.

| Tabla | Antes | Despues | Delta |
| --- | ---: | ---: | ---: |
| web_lotes_importacion | 0 | 1 | 1 |
| web_archivos_importados | 0 | 4 | 4 |
| web_registros_origen | 0 | 614460 | 614460 |
| web_personas | 0 | 21017 | 21017 |
| web_propietarios | 0 | 4082 | 4082 |
| web_inquilinos | 0 | 16935 | 16935 |
| web_inmuebles | 0 | 10384 | 10384 |
| web_contratos | 0 | 16935 | 16935 |
| web_contrato_inquilinos | 0 | 16935 | 16935 |
| web_contrato_propietarios | 0 | 16933 | 16933 |
| web_contrato_inmuebles | 0 | 16935 | 16935 |
| web_inmuebles_propietarios | 0 | 16929 | 16929 |
| web_cuentas_corrientes | 0 | 21017 | 21017 |
| web_conceptos_movimiento | 0 | 75 | 75 |
| web_movimientos_cuenta | 0 | 593443 | 593443 |

## Segunda Carga Completa

La segunda ejecucion uso los mismos parametros y produjo delta cero.

Metricas:

- Duracion: 486.012 segundos.
- Memoria pico: 226 MB.
- Errores: 0.

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

## Controles de Integridad

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

## Contratos Sin Propietario

Se detectaron 2 contratos sin relacion `web_contrato_propietarios`.

### Caso 1

| Campo | Valor |
| --- | --- |
| cuenta_inquilino_origen | `11032494107` |
| cuenta_propietario_origen | `12020977004` |
| codigo_origen | `11032494107|12020977004|NICASIO ORONO 638|01062026|31052028|01062026` |
| registro_origen_id | `18806` |
| linea INQUILINO.TXT | `14438` |
| fecha_inicio | `2026-06-01` |
| fecha_fin | `2028-05-31` |
| fecha_baja | `null` |
| marca_baja | vacia |
| propietario existe en PROPIETAR.TXT | no |

Motivo probable: `INQUILINO.TXT` referencia una cuenta propietaria activa que no esta presente en `PROPIETAR.TXT` del lote. No parece ser baja/historico por los campos de vigencia del contrato.

### Caso 2

| Campo | Valor |
| --- | --- |
| cuenta_inquilino_origen | `21030333301` |
| cuenta_propietario_origen | `22020101806` |
| codigo_origen | `21030333301|22020101806|PJE.82 BIS 2041 CASA 108|01062026|31052028|01062026` |
| registro_origen_id | `20952` |
| linea INQUILINO.TXT | `16932` |
| fecha_inicio | `2026-06-01` |
| fecha_fin | `2028-05-31` |
| fecha_baja | `null` |
| marca_baja | vacia |
| propietario existe en PROPIETAR.TXT | no |

Motivo probable: `INQUILINO.TXT` referencia una cuenta propietaria activa que no esta presente en `PROPIETAR.TXT` del lote. No parece ser baja/historico por los campos de vigencia del contrato.

Recomendacion: antes de generar liquidacion piloto, clasificar estos contratos como `SIN_PROPIETARIO_EN_LOTE` y revisar si las cuentas propietarias faltan por corte de archivo, filtro de COBOL o desincronizacion entre maestros.

## Comparacion Contra PostgreSQL 9.4

| Metrica | PostgreSQL 9.4 | PostgreSQL 17 |
| --- | ---: | ---: |
| Dry-run duracion | 0.689 s | 0.579 s |
| Dry-run memoria pico | 350.01 MB | 36 MB |
| Primera carga duracion | 624.883 s | 602.334 s |
| Primera carga memoria pico | 224 MB | 226 MB |
| Segunda carga duracion | 539.324 s | 486.012 s |
| Segunda carga memoria pico | 224 MB | 226 MB |
| Delta segunda ejecucion | 0 | 0 |

PostgreSQL 17 mejora moderadamente los tiempos, especialmente en la reejecucion, pero el cuello principal sigue estando en revalidar todo el lote y en el patron SQL compatible con PostgreSQL 9.4.

## Recomendacion Sobre ON CONFLICT

PostgreSQL 17 permite usar `ON CONFLICT`. Conviene incorporarlo en una etapa posterior como ruta optimizada version-aware, manteniendo compatibilidad o fallback para PostgreSQL 9.4 mientras sea necesario.

Recomendacion tecnica:

- mantener el modo actual compatible como fallback;
- agregar deteccion de version PostgreSQL;
- usar `insertOrIgnore`/`upsert` o SQL `ON CONFLICT DO NOTHING` para entidades con unique constraints simples;
- evaluar `COPY` o staging temporal para `web_registros_origen` y `web_movimientos_cuenta`;
- optimizar la segunda ejecucion para saltar chunks completos ya importados por `archivo_importado_id`, `numero_linea` y `hash_registro`.

No se implemento `ON CONFLICT` en esta verificacion porque el objetivo era validar el bulk existente contra PostgreSQL 17 sin cambiar comportamiento.

## Seguridad

- No se modifico `.env`.
- No se documentaron credenciales.
- No se uso `db_gei`.
- No se uso PostgreSQL 9.4 para esta prueba.
- No se tocaron tablas heredadas.
- No se generaron liquidaciones.
- No se modifico el generador PDF.
- No se integro UI.

## Decision

APTO_PARA_GENERAR_LIQUIDACION_PILOTO.

Advertencias:

- revisar los 2 contratos sin propietario antes de liquidar;
- implementar ruta optimizada con `ON CONFLICT`/staging para reducir tiempo de reejecucion;
- mantener controles de version/base antes de cualquier carga masiva.
