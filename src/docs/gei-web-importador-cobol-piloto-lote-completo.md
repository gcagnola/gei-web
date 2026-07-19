# Importador piloto COBOL - prueba de lote completo

## Resumen

Se intento validar el importador piloto COBOL contra los cuatro archivos completos en la base temporal `db_gei_web_migraciones_test`.

Resultado: **REQUIERE_AJUSTES**.

Motivo principal: el `dry-run` completo funciona y detecta correctamente el volumen, pero la carga completa no termino en un tiempo razonable para una prueba interactiva. Se interrumpio de forma controlada, la transaccion no dejo datos parciales y la base temporal quedo limpia con rollback.

No se toco `db_gei`, no se tocaron tablas heredadas y no se modifico el generador PDF.

## Entorno

- Rama Git: `feature/modelo-web-cobol`
- Base temporal: `db_gei_web_migraciones_test`
- Base protegida: `db_gei`
- Contenedor: `gei-app`

El comando confirma la base antes de ejecutar y aborta si:

- `DB_DATABASE=db_gei`;
- `DB_DATABASE` no es `db_gei_web_migraciones_test`.

## Archivos usados

Directorio:

```text
storage/app/private/liquidaciones/cobol
```

Archivos:

```text
PROPIETAR.TXT
INQUILINO.TXT
CTACTEPRO.TXT
INQCTACTE.TXT
```

## Comando dry-run completo

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -w /var/www/html gei-app \
  php artisan gei:web-importar-cobol-piloto \
    --base-dir=storage/app/private/liquidaciones/cobol \
    --sin-limite \
    --dry-run
```

Resultado:

```json
{
  "propietarios": 4082,
  "inquilinos": 16935,
  "movimientos_propietario": 234837,
  "movimientos_inquilino": 358606
}
```

Metricas:

```json
{
  "duracion_segundos": 0.786,
  "memoria_pico_mb": 350.01
}
```

Observacion: los movimientos detectados son menores al total bruto historico conocido porque el piloto filtra movimientos a cuentas con maestro cargado desde los archivos seleccionados.

## Preparacion de base temporal

Se aplicaron las migraciones `web_*` en orden sobre `db_gei_web_migraciones_test`:

```text
2026_07_17_120000_create_web_modelo_nuevo_importacion_tables
2026_07_17_121000_create_web_modelo_nuevo_personas_roles_tables
2026_07_17_122000_create_web_modelo_nuevo_inmuebles_contratos_tables
2026_07_17_123000_create_web_modelo_nuevo_cuentas_movimientos_tables
2026_07_17_124000_create_web_modelo_nuevo_liquidaciones_tables
2026_07_17_125000_create_web_modelo_nuevo_pagos_facturas_auditoria_tables
2026_07_17_130000_create_web_modelo_nuevo_liquidacion_cobol_support_tables
2026_07_17_131000_add_cobol_liquidacion_support_fields_to_web_modelo_nuevo_tables
```

## Comando de carga completa

```bash
docker exec -i --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e DB_DATABASE=db_gei_web_migraciones_test \
  -w /var/www/html gei-app \
  php artisan gei:web-importar-cobol-piloto \
    --base-dir=storage/app/private/liquidaciones/cobol \
    --sin-limite
```

## Resultado de carga completa

Primer intento:

- fallo por constraint `ck_web_contratos_fechas`;
- causa: contrato historico con `fecha_inicio > fecha_fin`;
- ejemplo detectado: cuenta inquilino `11031495110`, propietario `12020823509`;
- correccion segura aplicada en el piloto: si el rango normalizado queda incoherente, se conserva el raw en `web_registros_origen` y se deja `fecha_inicio = null` para no violar integridad.

Segundo intento:

- se optimizo la carga masiva de `web_registros_origen` y `web_movimientos_cuenta` por chunks;
- aun asi, la corrida completa no termino en tiempo razonable;
- se interrumpio manualmente;
- quedaron procesos Artisan del importador reteniendo recursos, fueron terminados;
- el rollback se completo luego de liberar esos procesos.

Conteos tras interrupcion:

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
  "web_cuentas_corrientes": 0,
  "web_movimientos_cuenta": 0
}
```

La transaccion evito datos parciales.

## Segunda ejecucion

No se ejecuto una segunda carga completa porque la primera carga completa no llego a terminar.

La idempotencia del piloto ya habia sido validada con carga limitada. Para lote completo queda pendiente repetirla despues de optimizar la fase de maestros, contratos y relaciones.

## Limpieza final

Se ejecuto rollback de las ocho migraciones `web_*` sobre `db_gei_web_migraciones_test`.

Verificacion final:

```text
web_tables=0
```

Tambien se verifico que no quedaran procesos Artisan activos del importador piloto o rollback.

## Integridad

No hubo una carga completa final sobre la cual medir:

- movimientos duplicados;
- registros origen duplicados;
- personas duplicadas;
- contratos duplicados;
- cuentas duplicadas;
- movimientos sin cuenta.

Estas validaciones quedan pendientes para la siguiente iteracion del piloto.

## Problemas detectados

1. Fechas historicas incoherentes en contratos
   - Hay contratos del archivo `INQUILINO.TXT` cuyo rango normalizado viola `fecha_inicio <= fecha_fin`.
   - El dato crudo queda trazado en `web_registros_origen`.
   - El piloto evita persistir el rango incoherente en columnas normalizadas.

2. Performance insuficiente para lote completo
   - El volumen detectado es de 4.082 propietarios, 16.935 inquilinos y 593.443 movimientos filtrados.
   - Se optimizaron movimientos por chunks, pero maestros/contratos/relaciones siguen con `updateOrInsert` fila a fila.
   - Requiere batching adicional antes de una prueba completa confiable.

3. Procesos largos
   - Al interrumpir el proceso desde la terminal, quedaron procesos Artisan del importador.
   - Fueron terminados manualmente.
   - El comando piloto debe incorporar manejo de señales o particionado por etapas antes de usarse para lotes grandes.

## Cambios aplicados al piloto

- Se agrego opcion `--sin-limite`.
- Se agregaron metricas de duracion y memoria pico.
- Se normalizan rangos de contrato incoherentes dejando `fecha_inicio = null`.
- Se agrego upsert por chunks para registros origen y movimientos.

## Limitaciones actuales

- No genera liquidaciones.
- No integra UI.
- No tiene particionado por archivo/etapa.
- No persiste reporte de errores de parseo por linea.
- No hace batching completo de maestros/relaciones.
- No tiene progreso incremental en consola.
- No maneja aun seniales para cancelar limpiamente sin procesos residuales.

## Decision

**REQUIERE_AJUSTES** antes de declarar el lote completo apto.

Siguiente paso recomendado:

1. dividir el importador en etapas (`maestros`, `relaciones`, `movimientos_propietario`, `movimientos_inquilino`);
2. aplicar upsert por chunks a maestros, contratos y relaciones;
3. persistir errores/anomalias en tablas `web_*`;
4. agregar progreso por etapa;
5. repetir lote completo temporal;
6. ejecutar segunda corrida completa para validar idempotencia.
