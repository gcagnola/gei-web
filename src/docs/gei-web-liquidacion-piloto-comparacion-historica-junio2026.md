# Comparacion historica junio 2026 desde web_*

## Resumen

Se repitio la comparacion de la cuenta propietaria `12020750010` usando un set coherente de archivos COBOL, el mismo corte que contiene los movimientos usados por las salidas historicas de junio 2026.

La prueba se ejecuto solamente contra PostgreSQL 17 temporal:

- PostgreSQL: `17.10`
- Base: `db_gei_web_migraciones_test`
- Resultado: no se toco `db_gei`, no se toco PostgreSQL 9.4 y no se modificaron tablas heredadas.

Decision: `REQUIERE_AJUSTES`.

La diferencia anterior se explicaba por archivos inconsistentes. Con el corte coherente, `web_*` encuentra los movimientos de junio, pero la reconstruccion piloto sigue usando una suma cruda de debe/haber. La salida historica aplica reglas de `GIMB23`: el movimiento `29` `Pago Liq.MAY/2026 A 00362443-` no debe descontarse del total final de la liquidacion junio.

## Archivos usados

Directorio fuente original:

```text
gei-liquidaciones-python/entrada/cobol/
```

Como el contenedor Laravel no ve esa ruta host, se copio el mismo set a:

```text
gei-web/src/storage/app/private/liquidaciones/cobol_junio2026/
```

No se modificaron los archivos originales.

| Archivo | Lineas | Bytes | SHA-256 |
| --- | ---: | ---: | --- |
| `PROPIETAR.TXT` | 4084 | 820884 | `111e120cf07650926879bf4891981de7484635a814c0c212cb71941766f5b09d` |
| `INQUILINO.TXT` | 16938 | 9502218 | `b94924c012b575d85880886a7b3ca0b8036e001e93cb860145d45049f56824d0` |
| `CTACTEPRO.TXT` | 239686 | 27084518 | `0cb4eaf3a576f39a6b434687dc91b1694a07a6fa764ff977da3e6971d2995d95` |
| `INQCTACTE.TXT` | 360876 | 48718260 | `f6229479ab4c79d4f9f4a80bf13b0ee92da8d50e280a62e2da965f93ff1bd1ce` |

## Comandos ejecutados

Validacion de conexion:

```bash
php artisan tinker --execute='select version(), current_database()'
```

Reinicio de la base temporal:

```bash
php artisan migrate:fresh --force
```

Carga bulk coherente:

```bash
php artisan gei:web-importar-cobol-piloto \
  --modo=bulk \
  --base-dir=storage/app/private/liquidaciones/cobol_junio2026 \
  --sin-limite \
  --chunk-size=5000
```

Reconstruccion:

```bash
php artisan gei:web-liquidacion-propietario-piloto \
  12020750010 \
  --periodo=202606 \
  --detalle-limite=200
```

Las credenciales se pasaron por variables de entorno y no se documentaron.

## Resultado de carga

Tiempo de carga bulk:

- duracion: `597.741` segundos
- memoria pico: `226 MB`
- errores: `0`

Resumen de candidatos:

| Tipo | Cantidad |
| --- | ---: |
| propietarios | 4084 |
| inquilinos | 16938 |
| movimientos_propietario | 239649 |
| movimientos_inquilino | 360876 |

Conteos cargados:

| Tabla | Registros |
| --- | ---: |
| `web_lotes_importacion` | 1 |
| `web_archivos_importados` | 4 |
| `web_registros_origen` | 621547 |
| `web_personas` | 21022 |
| `web_propietarios` | 4084 |
| `web_inquilinos` | 16938 |
| `web_inmuebles` | 10385 |
| `web_contratos` | 16938 |
| `web_contrato_inquilinos` | 16938 |
| `web_contrato_propietarios` | 16938 |
| `web_contrato_inmuebles` | 16938 |
| `web_inmuebles_propietarios` | 16934 |
| `web_cuentas_corrientes` | 21022 |
| `web_conceptos_movimiento` | 75 |
| `web_movimientos_cuenta` | 600525 |

Controles de integridad:

| Control | Resultado |
| --- | ---: |
| movimientos_sin_cuenta | 0 |
| registros_origen_sin_archivo | 0 |
| propietarios_sin_persona | 0 |
| inquilinos_sin_persona | 0 |
| contratos_sin_propietario | 0 |
| duplicados_movimiento_registro_origen | 0 |

## Salida historica

`pliqloc.sf.txt`, linea 52:

| Campo | Valor |
| --- | --- |
| Fecha | `19/06/2026` |
| Letra | `A` |
| Numero | `00363119` |
| Cuenta | `1202/07500/10` |
| Propietario | `LAS COLONIAS DISTRIBUCIONES S.A.` |
| CUIT | `30540938322` |
| Total | `7.712.042,63` |

`liquida.sf.txt`, lineas 1710-1807:

- periodo: `JUNIO 2026`
- cuenta: `1202/07500/10`
- comprobante visible: `363119`
- total final: `7.712.042,63`
- pago/banco: `B.B.V.A. BCO.FRANCES`, `C.Ahorro Com`
- total bancario impreso: `9.496.230,08`

`CTACTEPRO.TXT`, lineas 48964-49025:

- contiene los 62 movimientos de propietario para `12020750010` en `202606`;
- incluye alquileres/haberes, comisiones, gastos, bonificaciones, Litoral Gas y pago de liquidacion anterior.

## Resultado web_* junio 2026

Para:

```bash
php artisan gei:web-liquidacion-propietario-piloto 12020750010 --periodo=202606 --detalle-limite=200
```

Resultado:

| Campo | Valor |
| --- | ---: |
| Movimientos | 62 |
| Total debe crudo | 9.112.534,20 |
| Total haber crudo | 9.496.230,08 |
| Neto crudo `haber - debe` | 383.695,88 |

El total haber crudo coincide con el total bancario impreso en `liquida.sf.txt`:

```text
9.496.230,08
```

Pero el neto crudo no coincide con el total final historico:

```text
web_* crudo:       383.695,88
historico final: 7.712.042,63
```

## Desglose por concepto

| Codigo | Cantidad | Debe | Haber | IVA | Debe sin IVA |
| --- | ---: | ---: | ---: | ---: | ---: |
| `01` | 25 | 0,00 | 9.455.989,00 | 0,00 | 0,00 |
| `11` | 2 | 0,00 | 40.241,08 | 0,00 | 0,00 |
| `21` | 25 | 858.131,04 | 0,00 | 148.931,81 | 709.199,23 |
| `22` | 4 | 252.272,76 | 0,00 | 43.782,88 | 208.489,88 |
| `29` | 1 | 7.328.346,75 | 0,00 | 0,00 | 7.328.346,75 |
| `32` | 3 | 389.983,65 | 0,00 | 0,00 | 389.983,65 |
| `43` | 2 | 283.800,00 | 0,00 | 0,00 | 283.800,00 |

Formula que explica el historico:

```text
haber_total
- debe_total
+ movimiento codigo 29 Pago Liq.MAY/2026
= total_final_historico

9.496.230,08
- 9.112.534,20
+ 7.328.346,75
= 7.712.042,63
```

Esto muestra que el movimiento `29` se registra en la cuenta corriente, pero `GIMB23` no lo trata como descuento de la liquidacion corriente. Es una referencia/pago de liquidacion anterior y debe clasificarse aparte.

## Movimientos incluidos

La reconstruccion `web_*` ya incluye los movimientos historicos citados por `liquida.sf.txt`:

- `221823`, `221822`, `221991`, `222197`, `222198`, `222199`
- `222397`, `222398`, `222677`, `222766`, `222828`, `223287`
- `223815` a `223864`

Los signos y debe/haber cargados desde COBOL explican:

- alquileres y Litoral Gas detalle como `haber`;
- comisiones, gastos y bonificaciones como `debe`;
- pago de liquidacion anterior como `debe`, pero no liquidable para el total final actual.

## Diferencias remanentes

| Diferencia | Clasificacion | Detalle |
| --- | --- | --- |
| Neto crudo `383.695,88` vs total historico `7.712.042,63` | `REGLA_PENDIENTE_COBOL` / `MOVIMIENTO_NO_LIQUIDABLE` | El codigo `29` debe excluirse del calculo de total final corriente o tratarse como referencia de pago anterior. |
| Comprobante historico `A 00363119` no generado por piloto | `CORRELATIVO_PENDIENTE` | El piloto aun no reproduce `PROCORREL`/numeracion de liquidaciones. |
| `liquidado_origen = N` en movimientos usados historicamente | `CONSUMO_MOVIMIENTO_PENDIENTE` | Falta modelar la corrida que consume movimientos y registra equivalencia con `CTACTEPRO.LIQUIDADO = "S"`. |
| Orden e impresion de items | `REGLA_PENDIENTE_COBOL` | El piloto ordena por linea origen; falta reproducir orden final `LIQ.PROPI`. |
| Detalle/resumen de impuestos y servicios | `REGLA_PENDIENTE_COBOL` | `GIMB98`/`GIMB23` deben definir que se imprime, que suma y que queda como referencia. |

## Conclusion

La diferencia anterior por archivos inconsistentes quedo resuelta: con el corte junio 2026, `web_*` carga y expone los mismos movimientos que alimentan `liquida.sf.txt`.

La diferencia actual ya es funcional y concreta:

- la suma cruda del piloto no equivale a `GIMB23`;
- el codigo `29` debe quedar fuera del total final corriente;
- hace falta una capa de construccion de liquidacion que clasifique movimientos liquidables/no liquidables antes de generar items.

## Decision

`REQUIERE_AJUSTES`

No corresponde pasar a PDF piloto todavia. El siguiente paso es implementar una regla experimental de construccion de liquidacion que:

1. tome los movimientos de propietario por periodo;
2. excluya o clasifique aparte pagos de liquidaciones anteriores, empezando por codigo `29`;
3. conserve esos movimientos como referencia;
4. reproduzca totales netos/IVA de `GIMB23`;
5. compare nuevamente contra `pliqloc.sf.txt` y `liquida.sf.txt`.
