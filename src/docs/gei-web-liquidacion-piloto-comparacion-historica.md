# Comparacion historica de liquidacion piloto web_*

## Resumen

Se comparo la reconstruccion experimental desde `web_*` para la cuenta propietaria `12020750010` contra las salidas historicas disponibles.

La conexion Laravel fue validada antes de consultar:

- PostgreSQL: `17.10`
- Base: `db_gei_web_migraciones_test`
- Resultado: no se consulto ni modifico `db_gei`.

La comparacion no genera PDF, no modifica tablas heredadas, no integra UI y no escribe datos funcionales.

## Archivos historicos encontrados

La cuenta `12020750010`, equivalente impreso `1202/07500/10`, aparece en:

| Archivo | Lineas | Evidencia |
| --- | ---: | --- |
| `gei-liquidaciones-python/entrada/liquidaciones/pliqloc.sf.txt` | 52 | Comprobante `A 00363119`, fecha `19/06/2026`, total `7.712.042,63`. |
| `gei-liquidaciones-python/entrada/liquidaciones/liquida.sf.txt` | 1710-1807 | Liquidacion impresa de junio 2026, propietario, detalle, transportes, referencias y total. |
| `gei-liquidaciones-python/entrada/cobol/CTACTEPRO.TXT` | 48964-49025 | Movimientos COBOL de junio 2026 que explican el bloque de `liquida.sf.txt`. |

No se encontraron apariciones directas de la cuenta en:

- `liquida.st.txt`
- `liquidb.sf.txt`
- `liquidb.st.txt`
- `dailoc.SF.txt`
- `dailoc2.SF.txt`

## Comando web_* ejecutado

```bash
php artisan gei:web-liquidacion-propietario-piloto \
  12020750010 \
  --detalle-limite=200
```

Resultado desde `web_*`:

| Campo | Valor |
| --- | ---: |
| Periodo detectado | `202605` |
| Movimientos | 62 |
| Total debe | 1.752.142,25 |
| Total haber | 9.080.489,00 |
| Total neto | 7.328.346,75 |

La misma cuenta forzada a `--periodo=202606` devuelve:

| Campo | Valor |
| --- | ---: |
| Movimientos | 0 |
| Total debe | 0,00 |
| Total haber | 0,00 |
| Total neto | 0,00 |
| Contratos vigentes | encontrados |

Esto confirma que la base temporal `web_*` no contiene los movimientos de junio de 2026 para esa cuenta.

## Diferencia de lote COBOL cargado

La base temporal fue cargada con:

```text
storage/app/private/liquidaciones/cobol/CTACTEPRO.TXT
```

Archivo registrado en `web_archivos_importados`:

| Archivo | Lineas | Bytes | Hash |
| --- | ---: | ---: | --- |
| `CTACTEPRO.TXT` | 234874 | 26540762 | `cdc08bfc44df2fefc30af17a36c8d553a12d6eb63846629cdc5c11d31acb7e0c` |

La salida historica `liquida.sf.txt` corresponde al archivo disponible en:

```text
gei-liquidaciones-python/entrada/cobol/CTACTEPRO.TXT
```

Ese archivo no es el mismo:

| Archivo | Lineas | Hash |
| --- | ---: | --- |
| `gei-web/src/storage/app/private/liquidaciones/cobol/CTACTEPRO.TXT` | 234874 | `cdc08bfc44df2fefc30af17a36c8d553a12d6eb63846629cdc5c11d31acb7e0c` |
| `gei-liquidaciones-python/entrada/cobol/CTACTEPRO.TXT` | 239686 | `0cb4eaf3a576f39a6b434687dc91b1694a07a6fa764ff977da3e6971d2995d95` |

La diferencia explica por que `web_*` detecta como ultimo periodo `202605`, mientras que `liquida.sf.txt` y `pliqloc.sf.txt` son de `JUNIO 2026`.

## Salida historica

`pliqloc.sf.txt` registra:

| Campo | Valor |
| --- | --- |
| Fecha | `19/06/2026` |
| Letra | `A` |
| Numero | `00363119` |
| Cuenta | `1202/07500/10` |
| Propietario | `LAS COLONIAS DISTRIBUCIONES S.A.` |
| Condicion fiscal | `Resp.Inscripto` |
| CUIT | `30540938322` |
| Total | `7.712.042,63` |

`liquida.sf.txt` contiene tres paginas para la misma liquidacion:

- cabecera repetida en lineas 1710-1715, 1742-1747 y 1774-1779;
- periodo impreso: `JUNIO 2026`;
- numero/comprobante visible: `363119`;
- referencias de movimientos: `223815` a `223863` y otros movimientos `222397`, `222398`, `223816`, `221823`, `223287`, `221822`, `222677`, `222766`, `222828`;
- total final impreso: `7.712.042,63`;
- forma/destino de pago: `B.B.V.A. BCO.FRANCES`, `C.Ahorro Com`;
- total de pago bancario impreso: `9.496.230,08`.

## Movimientos COBOL que explican junio 2026

En `gei-liquidaciones-python/entrada/cobol/CTACTEPRO.TXT`, la cuenta tiene 62 movimientos en `202606`.

Resumen por codigo:

| Codigo | Interpretacion visible | Bruto | IVA | Neto |
| --- | --- | ---: | ---: | ---: |
| `01` | Alquileres / haberes de inquilinos | 9.455.989,00 | 0,00 | 9.455.989,00 |
| `11` | Detalle Litoral Gas | 40.241,08 | 0,00 | 40.241,08 |
| `21` | Comision p/Admin.Alquileres | 858.131,04 | 148.931,81 | 709.199,23 |
| `22` | Com.s/Imp,ExpyServ | 252.272,76 | 43.782,88 | 208.489,88 |
| `29` | Pago liquidacion anterior mayo 2026 | 7.328.346,75 | 0,00 | 7.328.346,75 |
| `32` | Gastos / expensas / gas agrupado | 389.983,65 | 0,00 | 389.983,65 |
| `43` | Bonificaciones alquiler junio 2026 | 283.800,00 | 0,00 | 283.800,00 |

Observaciones:

- El total `web_*` detectado para `202605` (`7.328.346,75`) aparece en el `CTACTEPRO.TXT` de junio como movimiento `29`, descripcion `Pago Liq.MAY/2026 A 00362443-`. Esto indica que la reconstruccion `web_*` actual corresponde al periodo anterior, no a la liquidacion historica de junio.
- El total historico de junio (`7.712.042,63`) se explica a partir de los movimientos junio, pero no mediante una suma cruda de debe/haber. COBOL aplica reglas de `GIMB23` y tratamiento de impuestos/servicios.
- El par de movimientos de Litoral Gas (`codigo 11`) y el movimiento resumido de gas (`codigo 32`) muestran que hay detalle y resumen sobre el mismo concepto. No deben interpretarse automaticamente como tres descuentos independientes.

## Comparacion de totales

| Concepto | web_* actual | Historico junio 2026 | Diferencia |
| --- | ---: | ---: | ---: |
| Periodo | `202605` | `202606` | distinto |
| Movimientos de propietario | 62 | 62 en COBOL junio, 34 referencias impresas principales | distinto lote |
| Total haber/alquileres | 9.080.489,00 | 9.455.989,00 | -375.500,00 |
| Total neto/final | 7.328.346,75 | 7.712.042,63 | -383.695,88 |
| Movimientos historicos `223815`-`223863` en `web_*` | 0 | presentes en `CTACTEPRO.TXT` junio | faltan por archivo cargado |

La diferencia no debe resolverse ajustando importes. La causa principal es que la base temporal fue cargada con un `CTACTEPRO.TXT` anterior al archivo que produjo las salidas historicas de junio.

## Conceptos presentes y faltantes

Presentes en `web_*` actual para `202605`:

- alquileres/haberes de mayo;
- comisiones de administracion;
- comisiones de impuestos/servicios;
- gastos bancarios;
- expensas y bonificaciones del periodo anterior;
- trazabilidad a `CTACTEPRO.TXT` cargado en `storage/app/private/liquidaciones/cobol`.

Faltantes respecto de la salida historica junio:

- movimientos `223815` a `223863`;
- movimientos `222397`, `222398`, `223816`, `221823`, `223287`, `221822`, `222677`, `222766`, `222828`;
- total historico `7.712.042,63`;
- comprobante `A 00363119`;
- regla exacta que excluye o neutraliza detalle/resumen de Litoral Gas para no duplicar efectos;
- relacion completa de `GIMB98` entre impuestos/servicios, `CTACTEPRO` y el item impreso.

## Reglas pendientes

Las diferencias que no se explican solo por lote de archivo quedan marcadas como `REGLA_PENDIENTE_COBOL`:

- `NOLIQ.PROPI`: no se aplica todavia en el piloto.
- Marca/consumo de movimientos liquidados: `CTACTEPRO.LIQUIDADO` se conserva como dato origen, pero falta modelar la tabla de consumo de movimientos por liquidacion/corrida.
- Cotizaciones: no se aplican cotizaciones ni paridades por item.
- Correlativos: no se reproduce `PROCORREL`/numeracion de liquidacion.
- Orden COBOL: el comando actual ordena por linea de origen, pero no reproduce todavia el orden final `LIQ.PROPI`.
- `GIMB98`: se observa que impuestos/servicios pueden generar detalle y resumen; falta modelar la regla que evita duplicar importes.
- `GIMB23`: falta reproducir la formula completa de total final, incluyendo netos, IVA y conceptos impresos/no impresos.

## Decision

`REQUIERE_AJUSTES`

La comparacion historica no puede darse por coincidente porque `web_*` fue cargado con un `CTACTEPRO.TXT` distinto al que genero `liquida.sf.txt` de junio 2026.

Antes de pasar a PDF piloto hay que:

1. cargar en PostgreSQL 17 temporal el mismo lote COBOL que contiene los movimientos de junio usados por `liquida.sf.txt`;
2. volver a ejecutar `gei:web-liquidacion-propietario-piloto 12020750010 --periodo=202606`;
3. implementar o simular las reglas `GIMB23`/`GIMB98` que transforman `CTACTEPRO` en `LIQ.PROPI`, especialmente impuestos/servicios, IVA, cotizaciones, correlativos y orden de impresion;
4. comparar nuevamente contra `pliqloc.sf.txt` y `liquida.sf.txt`.
