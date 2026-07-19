# Item builder piloto de liquidacion de propietario

## Resumen

Se implemento un armado experimental de items para transformar movimientos `web_*` en lineas funcionales equivalentes al bloque historico de `liquida.sf.txt`.

La prueba se ejecuto solamente contra PostgreSQL 17 temporal:

- PostgreSQL: `17.10`
- Base: `db_gei_web_migraciones_test`
- Cuenta: `12020750010`
- Periodo: `202606`
- Total historico esperado: `7.712.042,63`
- Total liquidable desde movimientos: `7.712.042,63`
- Total desde items construidos: `7.712.042,63`
- Diferencia items contra historico: `0,00`

No se toco `db_gei`, no se toco PostgreSQL 9.4, no se modificaron tablas heredadas y no se genero PDF.

Decision: `REQUIERE_AJUSTES`.

El builder cierra importes y replica la agrupacion principal observada en `GIMB23`, pero todavia es experimental. Falta generalizar contra mas cuentas, persistir relaciones movimiento-inquilino-inmueble, modelar el orden COBOL completo y resolver `GIMB98`/impuestos/servicios antes de pasar a PDF piloto.

## Comando

```bash
php artisan gei:web-liquidacion-propietario-piloto \
  12020750010 \
  --periodo=202606 \
  --detalle-limite=300 \
  --clasificar-movimientos \
  --construir-items \
  --total-esperado=7712042.63
```

El comando aborta si:

- `current_database()` es `db_gei`;
- `current_database()` no es `db_gei_web_migraciones_test`;
- `select version()` no contiene `PostgreSQL 17`.

## Regla implementada

Version:

```text
EXPERIMENTAL_GIMB23_ITEM_BUILDER_v1
```

Todas las reglas quedan marcadas como experimentales y no deben tratarse como definitivas.

| Codigo | Regla | Resultado |
| --- | --- | --- |
| `01` | Mantener individual | 25 items de alquiler |
| `11` | Mantener individual para Litoral Gas | 2 items |
| `21` | Agrupar neto sin IVA | 1 item `07,5% Comision p/Admin.Alquileres` |
| `22` | Agrupar neto sin IVA | 1 item `Com.s/Imp,ExpyServ.` |
| IVA `21+22` | Agrupar IVA importado desde movimientos | 1 item `21,0% IVA sobre comisiones` |
| `32` | Mantener individual | 3 items |
| `43` | Mantener individual | 2 items |
| `29` | Excluir de liquidacion corriente | 1 movimiento excluido como `REFERENCIA_LIQUIDACION_ANTERIOR` |

## Resultado

| Metrica | Valor |
| --- | ---: |
| Movimientos totales | 62 |
| Movimientos liquidables | 61 |
| Movimientos excluidos | 1 |
| Movimientos agrupados | 29 |
| Items construidos | 35 |
| Debe crudo | 9.112.534,20 |
| Haber crudo | 9.496.230,08 |
| Total crudo `haber - debe` | 383.695,88 |
| Total liquidable desde movimientos | 7.712.042,63 |
| Debe items | 1.784.187,45 |
| Haber items | 9.496.230,08 |
| Total items `haber - debe` | 7.712.042,63 |
| Diferencia movimientos vs historico | 0,00 |
| Diferencia items vs historico | 0,00 |

Movimiento excluido:

| Movimiento | Codigo | Detalle | Debe | Clasificacion |
| --- | --- | --- | ---: | --- |
| `221991` | `29` | `Pago Liq.MAY/2026 A 00362443-` | 7.328.346,75 | `REFERENCIA_LIQUIDACION_ANTERIOR` |

## Items generados

| Orden | Codigo | Descripcion | Debe | Haber | Movimientos origen | Comparacion historica |
| ---: | --- | --- | ---: | ---: | --- | --- |
| 1-25 | `01` | Alquileres individuales | 0,00 | 9.455.989,00 | `223815` a `223863` impares | Coincide 25/25 contra lineas `1721-1733` y `1754-1765` |
| 26 | `11` | `LITORAL GAS 2BIM26 2-2` | 0,00 | 6.232,43 | `222398` | Coincide con linea `1786` |
| 27 | `11` | `LITORAL GAS 2BIM26 1-2` | 0,00 | 34.008,65 | `222397` | Coincide con linea `1787` |
| 28 | `21` | `07,5% Comision p/Admin.Alquileres` | 709.199,23 | 0,00 | 25 movimientos `223816` a `223864` pares | Coincide con linea `1788` |
| 29 | `22` | `Com.s/Imp,ExpyServ.` | 208.489,88 | 0,00 | `221823`, `222197`, `222198`, `222199` | Coincide con linea `1789` |
| 30 | `IVA_21_22` | `21,0% IVA sobre comisiones` | 192.714,69 | 0,00 | 29 movimientos de codigos `21` y `22` | Coincide con linea `1803` |
| 31 | `32` | `GASTOS BANCARIOS` | 40.705,00 | 0,00 | `223287` | Coincide con linea `1790` |
| 32 | `32` | `LITORAL GAS 2BIM26 M. 60515673` | 40.241,08 | 0,00 | `221822` | Coincide con linea `1791` |
| 33 | `32` | `EXP.COMUNES MAY/2026` | 309.037,57 | 0,00 | `222677` | Coincide con linea `1792` |
| 34 | `43` | `BONIF.ALQ.JUN/26` | 139.829,00 | 0,00 | `222766` | Coincide con linea `1793` |
| 35 | `43` | `BONIF.ALQ.JUN/26` | 143.971,00 | 0,00 | `222828` | Coincide con linea `1794` |

## Agrupaciones

| Agrupacion | Movimientos | Bruto | IVA | Neto / item |
| --- | ---: | ---: | ---: | ---: |
| Codigo `21` administracion | 25 | 858.131,04 | 148.931,81 | 709.199,23 |
| Codigo `22` impuestos/servicios | 4 | 252.272,76 | 43.782,88 | 208.489,88 |
| IVA `21+22` | 29 | - | 192.714,69 | 192.714,69 |

El IVA sale del campo `iva` importado en `web_movimientos_cuenta`, no de un calculo nuevo del builder.

## Comparacion contra historico

| Control | Historico | Builder | Resultado |
| --- | ---: | ---: | --- |
| Total final | 7.712.042,63 | 7.712.042,63 | Coincide |
| Total bancario / haber | 9.496.230,08 | 9.496.230,08 | Coincide |
| Comision administracion neta | 709.199,23 | 709.199,23 | Coincide |
| Comision impuestos/servicios neta | 208.489,88 | 208.489,88 | Coincide |
| IVA comisiones | 192.714,69 | 192.714,69 | Coincide |
| Alquileres individuales | 25 | 25 | Coincide |
| Items funcionales historicos | 34 lineas + IVA | 35 items incluyendo IVA | Equivalente |

## Diferencias remanentes

| Diferencia | Estado | Motivo |
| --- | --- | --- |
| Descripcion de alquileres | `REGLA_PENDIENTE_COBOL` | En `CTACTEPRO` codigo `01` no trae texto; el impreso toma inquilino/inmueble desde relaciones COBOL. |
| Relacion movimiento-inquilino-inmueble | `REGLA_PENDIENTE_COBOL` | Los movimientos cargados no tienen aun `contrato_id`, `inquilino_id` e `inmueble_id` resueltos de forma directa. |
| Orden completo de impresion | `REGLA_PENDIENTE_COBOL` | El orden experimental reproduce este caso, pero falta confirmar reglas generales de `GIMB23`. |
| Litoral Gas detalle y resumen | `GIMB98_PENDIENTE` | Falta modelar la relacion completa entre impuestos/servicios, movimientos generados y presentacion impresa. |
| Codigo `29` | `EXPERIMENTAL_GIMB23` | La exclusion cierra este caso, pero falta generalizar con mas ejemplos y fuentes COBOL. |

## Riesgos

- El builder esta validado sobre una sola cuenta y un periodo.
- La regla de agrupacion de IVA debe probarse en propietarios con otras alicuotas o condiciones fiscales.
- Pueden existir codigos adicionales que `GIMB23` agrupe o excluya de otra manera.
- Falta resolver `NOLIQ.PROPI`, `CTACTEPRO.LIQUIDADO`, correlativos, cotizaciones y reversiones.
- No debe alimentar PDF productivo hasta completar la generalizacion.

## Decision

`REQUIERE_AJUSTES`

La salida ya es apta como base tecnica para seguir investigando items, pero todavia no es apta para PDF piloto. El siguiente paso deberia ser enriquecer cada movimiento con cuenta de inquilino/contrato/inmueble y validar el builder contra mas liquidaciones historicas.
