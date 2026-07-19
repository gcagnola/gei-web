# Generador PDF Piloto ReportLab

Esta carpeta contiene una copia piloto aislada del layout ReportLab usado por el motor actual de liquidaciones.

No es flujo productivo. No consulta PostgreSQL, no recalcula liquidaciones y no modifica el motor existente. Lee un JSON intermedio generado por GeI-Web y produce un PDF de revisión visual.

## Uso

```bash
python3 python/gei_liquidaciones_piloto/pdf_desde_json_web_piloto.py \
  storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json \
  --output storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto_reportlab_laravel.pdf
```

El entorno Python debe tener `reportlab` instalado.
