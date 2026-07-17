# Migraciones borrador del modelo nuevo GeI-Web

## Alcance

Se generaron migraciones Laravel borrador para el modelo nuevo `web_*` basado
en COBOL como fuente principal.

No se ejecuto `php artisan migrate`.
No se ejecuto SQL contra PostgreSQL.
No se modificaron tablas heredadas.
No se modifico codigo productivo ni generador PDF.

## Fuente

Las migraciones se basan en:

```text
gei-liquidaciones-python/salida/modelo-nuevo/ddl_web_modelo_nuevo_v2.sql
gei-liquidaciones-python/docs/gei-web-ddl-modelo-nuevo-revision.md
gei-liquidaciones-python/docs/gei-web-ddl-modelo-nuevo.md
```

## Migraciones generadas

### 1. Importacion y trazabilidad

```text
2026_07_17_120000_create_web_modelo_nuevo_importacion_tables.php
```

Tablas:

- `web_lotes_importacion`
- `web_archivos_importados`
- `web_registros_origen`

### 2. Personas y roles

```text
2026_07_17_121000_create_web_modelo_nuevo_personas_roles_tables.php
```

Tablas:

- `web_personas`
- `web_propietarios`
- `web_inquilinos`

### 3. Inmuebles y contratos

```text
2026_07_17_122000_create_web_modelo_nuevo_inmuebles_contratos_tables.php
```

Tablas:

- `web_inmuebles`
- `web_contratos`
- `web_contrato_inquilinos`
- `web_contrato_propietarios`
- `web_contrato_inmuebles`
- `web_inmuebles_propietarios`

### 4. Cuentas corrientes y movimientos

```text
2026_07_17_123000_create_web_modelo_nuevo_cuentas_movimientos_tables.php
```

Tablas:

- `web_cuentas_corrientes`
- `web_conceptos_movimiento`
- `web_movimientos_cuenta`

### 5. Liquidaciones

```text
2026_07_17_124000_create_web_modelo_nuevo_liquidaciones_tables.php
```

Tablas:

- `web_periodos`
- `web_liquidaciones_propietarios`
- `web_liquidaciones_impuestos_servicios`
- `web_liquidaciones_propietarios_items`
- `web_liquidaciones_pdfs`

### 6. Pagos, facturas y auditoria

```text
2026_07_17_125000_create_web_modelo_nuevo_pagos_facturas_auditoria_tables.php
```

Tablas:

- `web_facturas`
- `web_pagos`
- `web_auditoria_procesos`

Tambien agrega al final las FKs:

- `web_liquidaciones_propietarios_items.factura_id -> web_facturas.id`
- `web_liquidaciones_propietarios_items.pago_id -> web_pagos.id`

## Orden previsto de ejecucion

El orden esta dado por los timestamps de las migraciones:

1. trazabilidad base;
2. personas y roles;
3. inmuebles y contratos;
4. cuentas corrientes y movimientos;
5. liquidaciones;
6. pagos, facturas y auditoria;
7. FKs finales desde items hacia pagos/facturas dentro de la sexta migracion.

## Dependencias

Las FKs son internas al modelo `web_*`. No hay FKs hacia tablas heredadas como:

- `clientes`;
- `inmuebles`;
- `contratos`;
- `liquidaciones_de_clientes`;
- `liquidaciones_de_clientes_items`;
- `movimientos_de_cuentas`.

Esto mantiene el modelo nuevo desacoplado del contrato heredado.

## Diferencias frente al DDL v2

Las migraciones reflejan el DDL v2 con estas adaptaciones propias de Laravel:

- `bigint generated always as identity` se expresa con `$table->id()`.
- `timestamp with time zone` se expresa con `timestampTz()` y `timestampsTz()`.
- `jsonb` se expresa con `jsonb()`.
- Checks e indices de expresion se agregan con `DB::statement()`.
- Las FKs de items a facturas/pagos se crean en la ultima migracion, despues de
  existir las tablas destino.
- No se agregaron triggers para `updated_at`; quedara a cargo de Laravel o de
  una decision posterior.

## Como revisar sin ejecutar

Comandos seguros de lectura/sintaxis:

```bash
php -l database/migrations/2026_07_17_120000_create_web_modelo_nuevo_importacion_tables.php
php -l database/migrations/2026_07_17_121000_create_web_modelo_nuevo_personas_roles_tables.php
php -l database/migrations/2026_07_17_122000_create_web_modelo_nuevo_inmuebles_contratos_tables.php
php -l database/migrations/2026_07_17_123000_create_web_modelo_nuevo_cuentas_movimientos_tables.php
php -l database/migrations/2026_07_17_124000_create_web_modelo_nuevo_liquidaciones_tables.php
php -l database/migrations/2026_07_17_125000_create_web_modelo_nuevo_pagos_facturas_auditoria_tables.php
```

No se ejecuto:

```bash
php artisan migrate
```

Tampoco se ejecuto `migrate --pretend`, para evitar cualquier dependencia de
conexion/configuracion de base en esta etapa.

## Rollback previsto

Cada migracion tiene `down()` y elimina solo tablas `web_*` creadas por esa
migracion.

La sexta migracion suelta primero las FKs finales desde
`web_liquidaciones_propietarios_items` hacia `web_facturas` y `web_pagos`, y
luego elimina:

- `web_auditoria_procesos`;
- `web_pagos`;
- `web_facturas`.

## Riesgos antes de aplicar

- Los checks de estado son cerrados y deben validarse con la UI/importador.
- Los defaults `jsonb` usan `DB::raw`; deben probarse en una base descartable.
- Los indices de expresion son PostgreSQL-specific.
- El modelo no fue probado con `migrate --pretend` ni con una base aislada.
- La identidad definitiva de inmuebles sigue requiriendo reglas de merge
  auditado.
- Falta cerrar layouts de `liquida`, `liquidb`, `dailoc` y `pliqloc`.

## Checklist previo a migrate

Antes de aplicar en cualquier ambiente:

1. Revisar el SQL generado con `migrate --pretend` en una base descartable.
2. Confirmar que no existen tablas con los mismos nombres.
3. Confirmar si se usara `public.web_*` o un esquema separado.
4. Validar estados definitivos con la pantalla de importacion.
5. Validar volumen historico e indices.
6. Definir rollback operativo por grupo.
7. Confirmar backups del ambiente.
8. Ejecutar pruebas Laravel en base de test.
9. Aprobar explicitamente la ejecucion de migraciones.

## Estado

```text
MIGRACIONES_BORRADOR_GENERADAS
NO_EJECUTADAS
```
