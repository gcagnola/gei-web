# JSON intermedio de liquidacion piloto

## Resumen

Se agrego una salida JSON intermedia para la liquidacion piloto reconstruida desde `web_*`. El archivo queda pensado como entrada controlada para un futuro PDF piloto, sin invocar ni modificar el generador PDF productivo.

La prueba se ejecuto solamente contra:

- PostgreSQL: `17.10`
- Base: `db_gei_web_migraciones_test`
- Cuenta: `12020750010`
- Periodo: `202606`

No se toco `db_gei`, no se toco PostgreSQL 9.4, no se modificaron tablas heredadas, no se genero PDF y no se integró UI.

Decision: `APTO_PARA_PDF_PILOTO_CONTROLADO`.

El JSON es apto como contrato intermedio para una prueba de PDF controlada porque contiene encabezado, items, agrupaciones, excluidos, totales y advertencias experimentales. No es una salida productiva.

## Comando ejecutado

```bash
php artisan gei:web-liquidacion-propietario-piloto \
  12020750010 \
  --periodo=202606 \
  --detalle-limite=300 \
  --clasificar-movimientos \
  --construir-items \
  --total-esperado=7712042.63 \
  --export-json \
  --output=storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json
```

El comando aborta si:

- `current_database()` es `db_gei`;
- `current_database()` no es `db_gei_web_migraciones_test`;
- `select version()` no contiene `PostgreSQL 17`.

Las credenciales se pasaron por variables de entorno y no se documentaron.

## Archivo generado

```text
storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json
```

Tamaño:

```text
34663 bytes
```

Validacion de parseo:

```text
json_decode OK
total_items = 7712042.63
diferencia = 0.00
items = 35
```

## Estructura

El JSON contiene estas secciones principales:

```text
metadata
encabezado
resumen
items
movimientos_excluidos
agrupaciones
advertencias
```

### metadata

Campos:

- `version_regla`: version experimental del armado de items.
- `generado_en`: fecha/hora de generacion.
- `origen`: `WEB_PILOTO`.
- `base`: base temporal usada.
- `postgresql_version`: version PostgreSQL detectada.
- `advertencia`: `EXPERIMENTAL_NO_PRODUCTIVO`.

### encabezado

Campos:

- `cuenta_propietario`
- `propietario`
- `periodo`
- `periodo_texto`
- `comprobante_historico_tipo`
- `comprobante_historico_numero`
- `total_historico_esperado`
- `total_movimientos_liquidables`
- `total_items`
- `diferencia`

Para la cuenta piloto:

| Campo | Valor |
| --- | --- |
| `cuenta_propietario` | `12020750010` |
| `periodo` | `202606` |
| `periodo_texto` | `JUNIO 2026` |
| `comprobante_historico_tipo` | `A` |
| `comprobante_historico_numero` | `00363119` |
| `total_historico_esperado` | `7712042.63` |
| `total_movimientos_liquidables` | `7712042.63` |
| `total_items` | `7712042.63` |
| `diferencia` | `0.00` |

### resumen

| Campo | Valor |
| --- | ---: |
| `movimientos_totales` | 62 |
| `movimientos_liquidables` | 61 |
| `movimientos_excluidos` | 1 |
| `items_construidos` | 35 |
| `movimientos_agrupados` | 29 |

### items

Cada item incluye:

- `orden`
- `codigo`
- `codigo_item`
- `descripcion`
- `debe`
- `haber`
- `total`
- `clasificacion`
- `regla_aplicada`
- `movimientos_origen_ids`
- `numeros_movimiento_origen`
- `cantidad_movimientos_origen`
- `advertencias`

Resumen de items:

| Grupo | Items | Total |
| --- | ---: | ---: |
| Alquileres codigo `01` | 25 | 9.455.989,00 haber |
| Litoral Gas codigo `11` | 2 | 40.241,08 haber |
| Comision administracion codigo `21` | 1 | 709.199,23 debe |
| Comision impuestos/servicios codigo `22` | 1 | 208.489,88 debe |
| IVA codigos `21+22` | 1 | 192.714,69 debe |
| Gastos/servicios codigo `32` | 3 | 389.983,65 debe |
| Bonificaciones codigo `43` | 2 | 283.800,00 debe |

### movimientos_excluidos

El JSON conserva el movimiento excluido de la liquidacion corriente:

| Movimiento | Codigo | Detalle | Importe | Clasificacion |
| --- | --- | --- | ---: | --- |
| `221991` | `29` | `Pago Liq.MAY/2026 A 00362443-` | 7.328.346,75 | `REFERENCIA_LIQUIDACION_ANTERIOR` |

### agrupaciones

Agrupaciones exportadas:

| Codigo | Descripcion | Movimientos | Total |
| --- | --- | ---: | ---: |
| `21` | `07,5% Comision p/Admin.Alquileres` | 25 | 709.199,23 |
| `22` | `Com.s/Imp,ExpyServ.` | 4 | 208.489,88 |
| `IVA_21_22` | `IVA agrupado de comisiones` | 29 | 192.714,69 |

## Advertencias

El JSON incluye advertencias explicitas:

- `EXPERIMENTAL_NO_PRODUCTIVO`.
- Reglas `EXPERIMENTAL_GIMB23_ITEM_BUILDER`.
- Pendientes de `GIMB23/GIMB98`.
- Falta resolver relacion directa movimiento-inquilino-inmueble para todos los items.
- No usar como salida productiva ni generar liquidaciones reales.

## Validaciones ejecutadas

```bash
php -l app/Services/WebLiquidacionPropietarioPilotService.php
php -l routes/console.php
php artisan list gei
php artisan gei:web-liquidacion-propietario-piloto ... --export-json
php -r 'json_decode(file_get_contents(...), true, 512, JSON_THROW_ON_ERROR)'
php artisan test
```

Resultado:

```text
Laravel: 58 passed, 231 assertions
```

## Decision

`APTO_PARA_PDF_PILOTO_CONTROLADO`

El archivo intermedio cierra importes contra el historico y tiene estructura suficiente para una prueba de PDF aislada, siempre que el siguiente paso no modifique el generador productivo y se mantenga como piloto controlado.
