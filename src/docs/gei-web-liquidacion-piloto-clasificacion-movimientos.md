# Clasificacion experimental de movimientos GIMB23

## Resumen

Se agrego una capa experimental de clasificacion de movimientos para reconstruir la liquidacion de propietario desde `web_*`, sin generar PDF y sin escribir tablas funcionales.

La prueba se ejecuto solo contra:

- PostgreSQL: `17.10`
- Base: `db_gei_web_migraciones_test`

No se toco `db_gei`, no se toco PostgreSQL 9.4 y no se modificaron tablas heredadas.

## Cuenta y periodo

| Campo | Valor |
| --- | --- |
| Cuenta propietario | `12020750010` |
| Propietario | `LAS COLONIAS DISTRIBUCIONES S.A.` |
| Periodo | `202606` |
| Total historico esperado | `7.712.042,63` |
| Evidencia historica | `pliqloc.sf.txt` linea 52; `liquida.sf.txt` lineas 1710-1807 |

## Comando

```bash
php artisan gei:web-liquidacion-propietario-piloto \
  12020750010 \
  --periodo=202606 \
  --detalle-limite=200 \
  --clasificar-movimientos \
  --total-esperado=7712042.63
```

El comando aborta si:

- `current_database()` es `db_gei`;
- `current_database()` no es `db_gei_web_migraciones_test`;
- `select version()` no contiene `PostgreSQL 17`.

## Regla aplicada

Version:

```text
EXPERIMENTAL_GIMB23_v1
```

Clasificaciones disponibles:

- `LIQUIDABLE`
- `NO_LIQUIDABLE`
- `REFERENCIA_LIQUIDACION_ANTERIOR`
- `REGLA_PENDIENTE_COBOL`

Primera regla experimental:

```text
Si codigo_concepto = 29
o si el detalle contiene "Pago Liq."
entonces clasificar como REFERENCIA_LIQUIDACION_ANTERIOR
y excluir del total liquidable corriente.
```

La regla queda marcada como experimental. No debe generalizarse todavia a todos los propietarios sin seguir revisando `GIMB23`.

## Resultado

Totales crudos desde `web_movimientos_cuenta`:

| Metrica | Valor |
| --- | ---: |
| Movimientos totales | 62 |
| Debe crudo | 9.112.534,20 |
| Haber crudo | 9.496.230,08 |
| Neto crudo `haber - debe` | 383.695,88 |

Clasificacion:

| Metrica | Valor |
| --- | ---: |
| Movimientos liquidables | 61 |
| Movimientos excluidos | 1 |
| Debe excluido no liquidable | 7.328.346,75 |
| Haber excluido no liquidable | 0,00 |
| Total excluido no liquidable | 7.328.346,75 |
| Total liquidable | 7.712.042,63 |
| Diferencia contra historico | 0,00 |

Formula:

```text
total_liquidable =
  haber_liquidable
  - debe_liquidable

total_liquidable =
  9.496.230,08
  - (9.112.534,20 - 7.328.346,75)
  = 7.712.042,63
```

## Movimiento excluido

| Movimiento | Codigo | Fecha | Descripcion | Debe | Haber | Clasificacion |
| --- | --- | --- | --- | ---: | ---: | --- |
| `221991` | `29` | `2026-06-02` | `Pago Liq.MAY/2026 A 00362443-` | 7.328.346,75 | 0,00 | `REFERENCIA_LIQUIDACION_ANTERIOR` |

Motivo:

```text
Movimiento de pago/cancelacion de liquidacion anterior; se conserva como referencia y no descuenta el total corriente.
```

Regla aplicada:

```text
EXPERIMENTAL_GIMB23: codigo 29 o detalle con Pago Liq.
```

## Comparacion historica

| Fuente | Total |
| --- | ---: |
| `pliqloc.sf.txt` linea 52 | 7.712.042,63 |
| `liquida.sf.txt` lineas 1710-1807 | 7.712.042,63 |
| `web_*` crudo | 383.695,88 |
| `web_*` con clasificacion experimental | 7.712.042,63 |

La clasificacion experimental cierra exactamente para la cuenta `12020750010` y periodo `202606`.

## Riesgos de generalizacion

La regla `codigo 29` / `Pago Liq.` esta respaldada por este caso, pero todavia no prueba todos los escenarios COBOL.

Riesgos:

- Puede haber otros codigos de pago o cancelacion de liquidaciones previas.
- Puede haber movimientos codigo `29` que COBOL trate distinto en otros contextos.
- El texto puede variar entre `Pago Liq.`, `PAGO LIQ`, abreviaturas o formatos de comprobante.
- Hay que verificar si `GIMB23` usa una tabla/codigo formal en lugar de descripcion textual.
- La marca `CTACTEPRO.LIQUIDADO` y el consumo de movimientos todavia no estan modelados como corrida.

## Reglas pendientes

Antes de pasar a PDF piloto quedan pendientes:

- `NOLIQ.PROPI`: ordenes de no liquidar.
- `CTACTEPRO.LIQUIDADO`: consumo/reversion de movimientos por liquidacion.
- Correlativos: comprobante `A 00363119` y numeracion equivalente a `PROCORREL`.
- Cotizaciones/paridades.
- `GIMB98`: generacion y tratamiento de impuestos/servicios.
- Orden de impresion COBOL.
- Construccion de items finales, no solo total de movimientos.

## Decision

`REQUIERE_AJUSTES`

El total historico de la cuenta piloto ya se reproduce con una primera clasificacion experimental. Sin embargo, todavia no alcanza para PDF piloto porque falta construir la liquidacion completa de `GIMB23`: items, orden, correlativos, marcas de consumo, impuestos/servicios y reglas de exclusion adicionales.
