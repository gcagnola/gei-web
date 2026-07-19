# Comparacion de detalle de liquidacion piloto

## Resumen

Se comparo el detalle reconstruido desde `web_*` para la cuenta propietaria `12020750010`, periodo `202606`, contra el bloque historico impreso en `liquida.sf.txt`.

La prueba se ejecuto solamente contra PostgreSQL 17 temporal:

- PostgreSQL: `17.10`
- Base: `db_gei_web_migraciones_test`
- Cuenta: `12020750010`
- Periodo: `202606`
- Total historico esperado: `7.712.042,63`
- Total web liquidable: `7.712.042,63`
- Diferencia: `0,00`

No se toco `db_gei`, no se toco PostgreSQL 9.4, no se modificaron tablas heredadas y no se genero PDF.

Decision: `REQUIERE_AJUSTES`.

El total final ya coincide, pero el detalle impreso no es una copia directa de los 61 movimientos liquidables. `GIMB23` imprime algunos movimientos 1 a 1 y agrupa otros, especialmente comisiones e IVA. Todavia falta modelar formalmente la construccion de items, el orden de impresion y la relacion directa movimiento-inquilino-inmueble antes de pasar a PDF piloto.

## Fuentes usadas

| Fuente | Rango / ubicacion | Uso |
| --- | --- | --- |
| `liquida.sf.txt` | lineas `1710-1807` | Detalle historico impreso de la liquidacion |
| `pliqloc.sf.txt` | linea `52` | Control de comprobante y total |
| `CTACTEPRO.TXT` | lineas `48964-49025` | Movimientos COBOL de la cuenta y periodo |
| `web_movimientos_cuenta` | PostgreSQL 17 temporal | Movimientos cargados desde COBOL en `web_*` |

Datos historicos:

| Campo | Valor |
| --- | --- |
| Propietario | `LAS COLONIAS DISTRIBUCIONES S.A.` |
| Cuenta impresa | `1202/07500/10` |
| Periodo impreso | `JUNIO 2026` |
| Comprobante visible | `363119` |
| Comprobante control | `A 00363119` |
| Total final | `7.712.042,63` |
| Total bancario impreso | `9.496.230,08` |

## Comandos ejecutados

Validacion de conexion temporal:

```bash
php artisan tinker --execute='select version(), current_database()'
```

Extraccion del bloque historico:

```bash
nl -ba gei-liquidaciones-python/entrada/liquidaciones/liquida.sf.txt \
  | sed -n '1710,1807p'
```

Extraccion del tramo COBOL:

```bash
nl -ba gei-liquidaciones-python/entrada/cobol/CTACTEPRO.TXT \
  | sed -n '48964,49025p'
```

Reconstruccion web:

```bash
php artisan gei:web-liquidacion-propietario-piloto \
  12020750010 \
  --periodo=202606 \
  --detalle-limite=300 \
  --clasificar-movimientos \
  --total-esperado=7712042.63
```

Las credenciales se pasaron por variables de entorno y no se documentaron.

## Resultado web

| Metrica | Valor |
| --- | ---: |
| Movimientos totales en `web_*` | 62 |
| Movimientos liquidables | 61 |
| Movimientos excluidos | 1 |
| Debe crudo | 9.112.534,20 |
| Haber crudo | 9.496.230,08 |
| Neto crudo `haber - debe` | 383.695,88 |
| Debe excluido no liquidable | 7.328.346,75 |
| Total web liquidable | 7.712.042,63 |
| Diferencia contra historico | 0,00 |

Movimiento excluido:

| Movimiento | Codigo | Linea COBOL | Detalle | Importe | Clasificacion |
| --- | --- | ---: | --- | ---: | --- |
| `221991` | `29` | `48966` | `Pago Liq.MAY/2026 A 00362443-` | 7.328.346,75 | `REFERENCIA_LIQUIDACION_ANTERIOR` |

Regla aplicada:

```text
EXPERIMENTAL_GIMB23: codigo 29 o detalle con Pago Liq.
```

## Cantidad de items

La salida impresa contiene 34 lineas funcionales de detalle en el lado izquierdo del formulario:

| Tipo impreso | Lineas historicas | Movimientos web relacionados | Clasificacion |
| --- | ---: | ---: | --- |
| Alquileres por inquilino/inmueble | 25 | 25 | `ITEM_COINCIDE` |
| Litoral Gas detallado para Moschen | 2 | 2 | `ITEM_COINCIDE` |
| Comision administracion alquileres | 1 | 25 | `ITEM_AGRUPADO` |
| Comision sobre impuestos/expensas/servicios | 1 | 4 | `ITEM_AGRUPADO` |
| Gastos bancarios | 1 | 1 | `ITEM_COINCIDE` |
| Litoral Gas resumen | 1 | 1 | `ITEM_COINCIDE` |
| Expensas comunes | 1 | 1 | `ITEM_COINCIDE` |
| Bonificaciones alquiler | 2 | 2 | `ITEM_COINCIDE` |
| IVA de comisiones | 1 subtotal impreso | 29 | `ITEM_AGRUPADO` |

Por lo tanto, `61` movimientos liquidables se explican por `34` lineas impresas mas un subtotal de IVA. La diferencia de cantidad no es ausencia de movimientos: es una regla de agrupacion de `GIMB23`.

## Comparacion por grupo

| Grupo | Web_* | Historico | Resultado |
| --- | ---: | ---: | --- |
| Alquileres codigo `01` | 25 movimientos, haber `9.455.989,00` | 25 lineas, transporte `9.455.989,00` | `ITEM_COINCIDE` |
| Litoral Gas detalle codigo `11` | 2 movimientos, haber `40.241,08` | lineas `1786-1787`, total `40.241,08` | `ITEM_COINCIDE` |
| Comision alquileres codigo `21` | 25 movimientos, debe bruto `858.131,04`, IVA `148.931,81`, neto `709.199,23` | linea `1788`, `709.199,23`; IVA incluido en linea `1803` | `ITEM_AGRUPADO` |
| Comision impuestos/servicios codigo `22` | 4 movimientos, debe bruto `252.272,76`, IVA `43.782,88`, neto `208.489,88` | linea `1789`, `208.489,88`; IVA incluido en linea `1803` | `ITEM_AGRUPADO` |
| Gastos/expensas codigo `32` | 3 movimientos, debe `389.983,65` | lineas `1790-1792`, total `389.983,65` | `ITEM_COINCIDE` |
| Bonificaciones codigo `43` | 2 movimientos, debe `283.800,00` | lineas `1793-1794`, total `283.800,00` | `ITEM_COINCIDE` |
| Pago liquidacion anterior codigo `29` | 1 movimiento, debe `7.328.346,75` | no aparece como item corriente | `ITEM_SOLO_WEB` reclasificado como `REFERENCIA_LIQUIDACION_ANTERIOR` |

IVA impreso:

| Origen | IVA |
| --- | ---: |
| Codigo `21` | 148.931,81 |
| Codigo `22` | 43.782,88 |
| Total IVA impreso linea `1803` | 192.714,69 |

## Alquileres impresos

Los 25 movimientos codigo `01` aparecen individualmente en `liquida.sf.txt`.

| Orden impreso | Movimiento | Inquilino impreso | Inmueble impreso | Importe | Resultado |
| ---: | --- | --- | --- | ---: | --- |
| 1 | `223815` | `GRAZIANO ROSA ISABEL` | `4 DE ENERO 2867 P.1 DPTO 5` | 650.753,00 | `ITEM_COINCIDE` |
| 2 | `223817` | `MAZZUCCO JORGE RAUL` | `4 DE ENERO 2867 P.1 DPTO 3` | 656.640,00 | `ITEM_COINCIDE` |
| 3 | `223819` | `FERREYRA JAVIER NICOLAS` | `4 DE ENERO 2867 P.2 DPTO.8` | 650.753,00 | `ITEM_COINCIDE` |
| 4 | `223821` | `GOMEZ DANIEL MAXIMILIANO` | `4 DE ENERO 2867 P.4 DPTO.15` | 559.315,00 | `ITEM_COINCIDE` |
| 5 | `223823` | `FERREYRA JAVIER NICOLAS` | `4 DE ENERO 2867 COCH 1` | 70.766,00 | `ITEM_COINCIDE` |
| 6 | `223825` | `ODETTI MELINA` | `4 DE ENERO 2867 P.B D.1` | 684.000,00 | `ITEM_COINCIDE` |
| 7 | `223827` | `GIUGNI MARIA CRISTINA` | `4 DE ENERO 2867 COCH 17` | 70.766,00 | `ITEM_COINCIDE` |
| 8 | `223829` | `VICARIO MOLEON MARIA BEATRIZ` | `AV ARIST DEL VALLE 4545 P.ALTA` | 1.191.254,00 | `ITEM_COINCIDE` |
| 9 | `223831` | `LISOWYJ PABLO CESAR` | `4 DE ENERO 2867 COCH 15` | 70.766,00 | `ITEM_COINCIDE` |
| 10 | `223833` | `SERRANO CRISTINA LILIAN` | `4 DE ENERO 2867 P.3 D.14 COCH` | 667.412,00 | `ITEM_COINCIDE` |
| 11 | `223835` | `ZAPATA JULIO CESAR` | `4 DE ENERO 2867 P.3 D.13` | 675.963,00 | `ITEM_COINCIDE` |
| 12 | `223837` | `MORERO NICOLAS` | `4 DE ENERO 2867 COCH.5` | 70.766,00 | `ITEM_COINCIDE` |
| 13 | `223839` | `AMADIO IGNACIO` | `4 DE ENERO 2867 P.B D.2` | 500.005,00 | `ITEM_COINCIDE` |
| 14 | `223841` | `FERNANDEZ HUGO DANIEL` | `4 DE ENERO 2867 COCH.8` | 75.000,00 | `ITEM_COINCIDE` |
| 15 | `223843` | `MOSCHEN ETELVINA JUDIT` | `4 DE ENERO 2867 P.2 D.7` | 435.995,00 | `ITEM_COINCIDE` |
| 16 | `223845` | `COURAULT JOAQUIN Y SPESSO GABRIEL` | `4 DE ENERO 2867 P.4 D.16` | 429.935,00 | `ITEM_COINCIDE` |
| 17 | `223847` | `GENERO YANINA E. Y ROSSI RODRIGO` | `4 DE ENERO 2867 P.3 D.11` | 435.761,00 | `ITEM_COINCIDE` |
| 18 | `223849` | `BARAVALLE CANDELA BELEN` | `4 DE ENERO 2867 COCH.14` | 76.727,00 | `ITEM_COINCIDE` |
| 19 | `223851` | `CARNIELLI MARIA VICTORIA` | `4 DE ENERO 2867 P.4 D.17` | 575.885,00 | `ITEM_COINCIDE` |
| 20 | `223853` | `MEYER ADRIANA CECILIA` | `4 DE ENERO 2867 COCH 06` | 75.000,00 | `ITEM_COINCIDE` |
| 21 | `223855` | `CARRANZA DEBORA DANIELA` | `4 DE ENERO 2867 COCH.13` | 61.478,00 | `ITEM_COINCIDE` |
| 22 | `223857` | `PAPAROTTI LUCIANA` | `4 DE ENERO 2867 COCH 3` | 62.545,00 | `ITEM_COINCIDE` |
| 23 | `223859` | `CARDONA OLGA ELIZABETH` | `4 DE ENERO 2867 P.2 DPTO.9` | 566.602,00 | `ITEM_COINCIDE` |
| 24 | `223861` | `DE GIOVANNI SONIA TERESITA` | `4 DE ENERO 2867 COCH 19` | 70.766,00 | `ITEM_COINCIDE` |
| 25 | `223863` | `PAVAN DARIO ALBERTO` | `4 DE ENERO 2867 COCH 10` | 71.136,00 | `ITEM_COINCIDE` |

## Diferencias remanentes

| Diferencia | Clasificacion | Motivo |
| --- | --- | --- |
| `61` movimientos liquidables contra `34` lineas impresas | `ITEM_AGRUPADO` | `GIMB23` agrupa comisiones e IVA y no imprime cada movimiento de comision por separado. |
| Orden web por archivo/fecha contra orden impreso por secciones | `DIFERENCIA_ORDEN` | El orden de impresion COBOL no esta modelado todavia como `orden_liquidacion` / `orden_impresion`. |
| Movimientos codigo `21` y `22` impresos netos, con IVA separado | `ITEM_AGRUPADO` | El item builder debe partir bruto/IVA/neto segun regla COBOL. |
| Vinculo directo movimiento-inquilino-inmueble aparece en `CTACTEPRO`, pero no esta resuelto en cada movimiento web | `REGLA_PENDIENTE_COBOL` | Falta persistir o derivar la cuenta de inquilino origen en el movimiento y enlazarla al contrato/inmueble correcto. |
| Litoral Gas aparece como detalle y tambien como movimiento resumen de gasto | `GIMB98_PENDIENTE` | Falta modelar formalmente la relacion entre movimientos generados por `GIMB98`, impuestos/servicios y presentacion en liquidacion. |
| Movimiento `221991` existe en cuenta corriente pero no en detalle corriente | `ITEM_SOLO_WEB` reclasificado | Se excluye como `REFERENCIA_LIQUIDACION_ANTERIOR`, regla experimental pendiente de generalizar contra `GIMB23`. |

## Reglas pendientes

- Construccion formal de items de liquidacion desde movimientos.
- Orden de impresion COBOL.
- Regla completa de agrupacion de comisiones e IVA.
- Vinculo movimiento-inquilino-inmueble por cuenta de inquilino origen.
- Tratamiento completo de `GIMB98`, `dailoc` e impuestos/servicios.
- `NOLIQ.PROPI`.
- Marca y consumo de `CTACTEPRO.LIQUIDADO`.
- Correlativos y comprobante `A 00363119`.
- Cotizaciones/paridades si aparecen en otros casos.

## Decision

`REQUIERE_AJUSTES`

La comparacion de detalle valida que los importes historicos estan en `web_*` y que el total liquidable coincide exactamente con la salida historica. Sin embargo, todavia no alcanza para PDF piloto porque falta transformar los movimientos liquidables en items finales con la misma agrupacion, orden y semantica de `GIMB23`.
