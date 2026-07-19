# Liquidacion piloto desde modelo web_*

## Resumen

Se agrego una reconstruccion experimental de liquidacion de propietario desde el modelo nuevo `web_*`, usando exclusivamente la base temporal PostgreSQL 17:

- host: `192.168.50.20`
- puerto: `5430`
- base: `db_gei_web_migraciones_test`
- version detectada: `PostgreSQL 17.10`

La prueba no toca `db_gei`, no consulta PostgreSQL 9.4, no modifica tablas heredadas, no genera PDF y no integra UI.

## Comando experimental

Se agrego el comando:

```bash
php artisan gei:web-liquidacion-propietario-piloto \
  12020240300 \
  --detalle-limite=50
```

Opciones:

- `cuenta`: cuenta de propietario. Valor por defecto: `12020240300`.
- `--periodo=AAAAMM`: periodo manual. Si no se informa, toma el ultimo periodo con movimientos de propietario.
- `--detalle-limite=N`: cantidad maxima de movimientos devueltos en el detalle JSON.

El comando aborta si:

- `current_database()` es `db_gei`;
- `current_database()` no es `db_gei_web_migraciones_test`;
- `select version()` no contiene `PostgreSQL 17`.

## Tablas consultadas

La reconstruccion es de solo lectura y consulta:

- `web_propietarios`
- `web_personas`
- `web_cuentas_corrientes`
- `web_movimientos_cuenta`
- `web_conceptos_movimiento`
- `web_registros_origen`
- `web_contrato_propietarios`
- `web_contratos`
- `web_contrato_inquilinos`
- `web_inquilinos`
- `web_contrato_inmuebles`
- `web_inmuebles`

No se ejecutan `INSERT`, `UPDATE` ni `DELETE`.

## Preparacion usada

La base temporal ya tenia aplicada la carga bulk completa:

| Tabla | Registros |
| --- | ---: |
| `web_lotes_importacion` | 1 |
| `web_archivos_importados` | 4 |
| `web_registros_origen` | 614460 |
| `web_personas` | 21017 |
| `web_propietarios` | 4082 |
| `web_inquilinos` | 16935 |
| `web_inmuebles` | 10384 |
| `web_contratos` | 16935 |
| `web_contrato_inquilinos` | 16935 |
| `web_contrato_propietarios` | 16933 |
| `web_contrato_inmuebles` | 16935 |
| `web_inmuebles_propietarios` | 16929 |
| `web_cuentas_corrientes` | 21017 |
| `web_conceptos_movimiento` | 75 |
| `web_movimientos_cuenta` | 593443 |

## Cuenta piloto solicitada

Cuenta: `12020240300`

Propietario:

- nombre: `LOPEZ DORA SUC.DE, MYRIAM Y MARIA C`
- CUIT: `030715398814`
- domicilio: `PAVON 346`
- localidad: `SANTA FE`
- forma de pago: `70`
- subforma: `1`
- comision administracion: `10.000`
- comision impuestos: `10.000`

Periodo detectado:

- `202603`
- criterio: ultimo periodo con movimientos de propietario.

Totales reconstruidos:

| Metrica | Valor |
| --- | ---: |
| Movimientos | 3 |
| Total debe | 8540.19 |
| Total haber | 0.00 |
| Total neto | -8540.19 |

Detalle fuente:

| Linea `CTACTEPRO.TXT` | Fecha | Concepto | Movimiento | Debe | Haber |
| ---: | --- | --- | --- | ---: | ---: |
| 8080 | 2026-03-02 | `22` `Com.s/Imp,ExpyServ` | 206662 | 911.49 | 0.00 |
| 8081 | 2026-03-02 | `27` `Percepcion NoCateg.en IVA` | 206663 | 95.70 | 0.00 |
| 8082 | 2026-03-02 | `31` `Pago Imptos del mes s/detalle` | 206661 | 7533.00 | 0.00 |

Contratos/inquilinos relacionados:

- No se encontraron contratos vigentes para el periodo `202603`.
- Se filtro por vigencia contractual: `fecha_inicio <= fin_periodo`, `fecha_fin >= inicio_periodo` y `fecha_baja` nula o posterior/al inicio del periodo.

Advertencias:

- `REGLA_PENDIENTE_COBOL`: esta reconstruccion suma movimientos de propietario por periodo; aun no reproduce `GIMB23` completo.
- `REGLA_PENDIENTE_COBOL`: no se aplican todavia `NOLIQ.PROPI`, cotizaciones, correlativos ni marcas de consumo de liquidacion.
- Los movimientos de propietario no tienen `contrato_id` directo; la relacion con inquilinos se infiere por contratos vigentes del propietario cuando existen.

## Cuenta alternativa representativa

Se probo tambien una cuenta con mayor volumen para validar detalle, totales y relaciones:

```bash
php artisan gei:web-liquidacion-propietario-piloto \
  12020750010 \
  --periodo=202605 \
  --detalle-limite=20
```

Propietario:

- cuenta: `12020750010`
- nombre: `LAS COLONIAS DISTRIBUCIONES S.A.`
- CUIT: `030540938322`
- domicilio: `EST.ZEBALLOS 3708`
- forma de pago: `73`
- subforma: `1`

Totales reconstruidos:

| Metrica | Valor |
| --- | ---: |
| Movimientos | 62 |
| Total debe | 1752142.25 |
| Total haber | 9080489.00 |
| Total neto | 7328346.75 |

La salida devolvio contratos/inquilinos vigentes relacionados para el periodo, por ejemplo:

- `11032398209` / `MAZZUCCO JORGE RAUL` / `4 DE ENERO 2867 P.1 DPTO 3`
- `11032400209` / `GOMEZ DANIEL MAXIMILIANO` / `4 DE ENERO 2867 P.4 DPTO.15`
- `11032396705` / `GRAZIANO ROSA ISABEL` / `4 DE ENERO 2867 P.1 DPTO 5`
- `11032400100` / `FERREYRA JAVIER NICOLAS` / `4 DE ENERO 2867 P.2 DPTO.8`

## Controles de integridad

Controles ejecutados sobre la base temporal:

| Control | Resultado |
| --- | ---: |
| movimientos_sin_cuenta | 0 |
| registros_origen_sin_archivo | 0 |
| propietarios_sin_persona | 0 |
| inquilinos_sin_persona | 0 |
| contratos_sin_inquilino | 0 |
| contratos_sin_inmueble | 0 |
| contratos_sin_propietario | 2 |
| duplicados_archivo_linea | 0 |
| duplicados_movimiento_registro_origen | 0 |
| duplicados_cuentas_dominio_cuenta | 0 |

Los 2 contratos sin propietario corresponden a casos ya detectados en la carga bulk y deben clasificarse como `SIN_PROPIETARIO_EN_LOTE`: el propietario referenciado por `INQUILINO.TXT` no existe en `PROPIETAR.TXT`.

## Reglas pendientes

La prueba reconstruye una liquidacion basica de propietario desde movimientos persistidos, pero todavia no equivale a una liquidacion COBOL completa.

Pendientes antes de PDF piloto:

- Reproducir el flujo `GIMB132`/`GIMB133`/`GIMB134` -> `/tmp/LIQ.PROPI.CON` -> `GIMB23`.
- Aplicar ordenes `NOLIQ.PROPI`.
- Modelar cotizaciones/paridades usadas por item y por corrida.
- Registrar y consultar marcas de consumo equivalentes a `CTACTEPRO.LIQUIDADO = "S"` mediante `web_liquidaciones_movimientos`.
- Definir orden COBOL de item/liquidacion/impresion.
- Asociar movimientos de propietario con contrato/inquilino/inmueble cuando COBOL lo permita; hoy muchos movimientos solo conservan cuenta propietaria y fuente.
- Comparar los totales contra `LIQ.PROPI` o la salida historica equivalente antes de generar PDF.

## Decision

`REQUIERE_AJUSTES`

El modelo `web_*` ya permite consultar propietario, movimientos, totales, trazabilidad y relaciones contractuales disponibles. Sin embargo, la salida todavia es una reconstruccion contable basica por periodo, no la liquidacion completa que produce COBOL. Antes de pasar a PDF piloto hay que incorporar las reglas de seleccion, consumo y orden de `GIMB23`, y validar contra una salida historica de liquidacion.
