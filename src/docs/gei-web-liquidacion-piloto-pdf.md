# PDF piloto desde JSON intermedio web_*

## Resumen

Se genero un PDF piloto controlado desde el JSON intermedio de liquidacion reconstruido desde `web_*`.

El PDF se genero sin consultar PostgreSQL, sin recalcular la liquidacion, sin modificar el generador PDF productivo y sin tocar tablas heredadas.

Decision: `APTO_PARA_REVISION_VISUAL`.

El archivo es una salida aislada, marcada como piloto/no productiva, suficiente para revisar visualmente encabezado, items, totales, movimientos excluidos y advertencias. No reemplaza el PDF historico ni el generador productivo.

## Archivos

JSON usado:

```text
storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json
```

PDF generado:

```text
storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.pdf
```

Tamaño:

```text
7200 bytes
```

Encabezado validado:

```text
%PDF-1.4
```

## Comando ejecutado

```bash
php artisan gei:web-liquidacion-pdf-piloto \
  storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json \
  --output=storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.pdf
```

Resultado:

```json
{
  "estado": "PDF_PILOTO_GENERADO",
  "json": "storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json",
  "output": "storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.pdf",
  "bytes": 7200,
  "total": "7712042.63",
  "items": 35,
  "advertencia": "PILOTO_NO_PRODUCTIVO"
}
```

## Validaciones internas

El servicio piloto valida antes de generar:

- el JSON existe;
- el JSON parsea correctamente;
- `metadata.advertencia` es `EXPERIMENTAL_NO_PRODUCTIVO`;
- `metadata.origen` es `WEB_PILOTO`;
- `encabezado.diferencia` es `0.00`;
- `encabezado.total_items` coincide con la suma `haber - debe` de los items.

Si cualquiera de esas condiciones falla, el comando aborta y no genera PDF.

## Contenido renderizado

El PDF incluye:

- marca visible `PILOTO / NO PRODUCTIVO`;
- ruta del JSON origen;
- version de regla experimental;
- fecha de generacion informada por el JSON;
- propietario;
- cuenta;
- periodo;
- comprobante historico esperado;
- total historico;
- total items;
- diferencia;
- resumen de movimientos;
- tabla de items;
- movimientos excluidos;
- advertencias.

## Totales

| Control | Valor |
| --- | ---: |
| Total historico | 7.712.042,63 |
| Total items JSON | 7.712.042,63 |
| Diferencia | 0,00 |
| Items | 35 |
| Movimientos totales | 62 |
| Movimientos liquidables | 61 |
| Movimientos excluidos | 1 |
| Movimientos agrupados | 29 |

## Diferencias visuales conocidas

Este PDF piloto no intenta copiar todavia el formulario historico de `liquida.sf.txt`.

Diferencias esperadas:

- no imprime doble talon/copia como el historico;
- no replica saltos de pagina COBOL ni controles de impresora;
- no usa el layout exacto de columnas historicas;
- no resuelve aun nombre/inmueble para cada item de alquiler desde el movimiento;
- no formatea banco, firma ni leyendas finales como el historico;
- no usa el generador PDF productivo.

## Seguridad

La generacion:

- no toca `db_gei`;
- no toca PostgreSQL 9.4;
- no consulta tablas heredadas;
- no recalcula la liquidacion desde DB;
- no genera emails;
- no integra UI;
- no modifica el generador PDF productivo;
- solo lee el JSON intermedio y escribe un PDF en `storage/app/private/liquidaciones/piloto`.

## Validaciones ejecutadas

```bash
php -l app/Services/WebLiquidacionPropietarioPdfPilotService.php
php -l routes/console.php
php artisan list gei
php artisan gei:web-liquidacion-pdf-piloto ... --output=...
test -s storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.pdf
head -c 8 storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.pdf
php artisan test
```

## Decision

`APTO_PARA_REVISION_VISUAL`

El PDF piloto es valido como artefacto de revision visual controlada. Para avanzar hacia un PDF comparable al historico, el siguiente paso es mejorar el modelo visual y enriquecer los items con inquilino/inmueble antes de conectar cualquier flujo productivo.
