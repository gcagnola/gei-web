# PDF piloto ReportLab dentro de GeI-Web

## Resumen

Se preparó una copia piloto aislada del generador PDF basado en ReportLab dentro del repositorio Laravel, sin modificar `gei-liquidaciones-python`.

El objetivo es validar que el JSON intermedio construido desde `web_*` pueda alimentar el layout ya resuelto, pero dejando intacto el motor actual de generación.

Decisión: `APTO_PARA_REVISION_VISUAL_CON_LAYOUT_REAL`.

## Qué se copió

Referencia de lectura:

- `gei-liquidaciones-python/src/gei_liquidaciones/main.py`
- `gei-liquidaciones-python/config/config.json`
- `gei-liquidaciones-python/src/gei_liquidaciones/GeI_fox.png`

Copia piloto en Laravel:

- `python/gei_liquidaciones_piloto/generar_pdf.py`
- `python/gei_liquidaciones_piloto/pdf_desde_json_web_piloto.py`
- `python/gei_liquidaciones_piloto/config.json`
- `python/gei_liquidaciones_piloto/GeI_fox.png`
- `python/gei_liquidaciones_piloto/README.md`

No se modificó el origen de referencia.

## JSON usado

```text
storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json
```

Validaciones aplicadas:

- JSON parseable.
- `metadata.origen = WEB_PILOTO`.
- `metadata.advertencia = EXPERIMENTAL_NO_PRODUCTIVO`.
- `encabezado.diferencia = 0.00`.
- `encabezado.total_items = 7712042.63`.
- La suma contable `sum(items[*].haber) - sum(items[*].debe)` coincide con `total_items`.

## Comando ejecutado

```bash
/home/gcagnola/proyectos/gei-liquidaciones-python/.venv/bin/python \
  python/gei_liquidaciones_piloto/pdf_desde_json_web_piloto.py \
  storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json \
  --output storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto_reportlab_laravel.pdf
```

Se usó ese intérprete porque el Python global y el contenedor Laravel no tienen `reportlab` instalado. No se escribió nada en `gei-liquidaciones-python`.

## PDF generado

```text
storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto_reportlab_laravel.pdf
```

Resultado:

- PDF generado correctamente.
- Header: `%PDF-1.4`.
- Tamaño: 37282 bytes.
- Ítems renderizados: 35.
- Total renderizado: 7712042.63.
- Total Debe: 1784187.45.
- Total Haber: 9496230.08.
- Neto gravado: 917689.11.
- IVA: 192714.69.

## Adaptación JSON a ReportLab

Mapeo principal:

| JSON web_* | Objeto ReportLab |
| --- | --- |
| `encabezado.cuenta_propietario` | `Liquidacion.cuenta` con formato `1202/07500/10` |
| `encabezado.propietario.nombre` | `Liquidacion.propietario` |
| `encabezado.propietario.domicilio` | `Liquidacion.domicilio` |
| `encabezado.propietario.cuit` | `Liquidacion.cuit` |
| `encabezado.periodo_texto` | `Liquidacion.periodo` |
| `encabezado.comprobante_historico_tipo` | `Liquidacion.tipo` |
| `encabezado.comprobante_historico_numero` | `Liquidacion.comprobante` y `numero_interno` |
| `encabezado.total_items` | `Liquidacion.total_final` |
| `items[*].descripcion` | `Item.detalle` |
| `items[*].debe` | `Item.debe` |
| `items[*].haber` | `Item.haber` |
| `items[*].numeros_movimiento_origen` | `Item.referencia` |
| `agrupaciones[21,22]` | `Liquidacion.total_neto_gravado` |
| `agrupaciones[IVA_21_22]` | `Liquidacion.total_iva` |

## Campos aún incompletos

Estos datos faltan o están aproximados en el JSON piloto:

- Fecha histórica exacta de liquidación.
- Condición IVA real desde maestro definitivo.
- Código postal.
- Forma de pago/banco.
- Inquilino e inmueble por cada ítem de alquiler.
- Vencimientos por ítem.
- Copropietario y porcentaje cuando aplique.

No impiden la revisión visual del layout, pero deben resolverse antes de producción.

## Advertencias

- El PDF incluye marca `PILOTO / NO PRODUCTIVO`.
- No se recalcula la liquidación para generar el PDF.
- No se consulta PostgreSQL.
- No se integra UI.
- No se envían emails.
- No se reemplaza el generador productivo.
- No se modifica `gei-liquidaciones-python`.

## Próximo paso

Revisión visual del PDF piloto contra la liquidación histórica. Después, completar en el JSON los campos todavía faltantes antes de convertir esta copia piloto en un adaptador mantenible dentro de GeI-Web.
