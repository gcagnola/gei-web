# Runtime Python propio para PDF piloto ReportLab

## Objetivo

Dejar el piloto de generación PDF dentro de `gei-web` sin depender del entorno virtual de `gei-liquidaciones-python`.

La carpeta `gei-liquidaciones-python` queda fuera del desarrollo de este flujo y continúa como motor actual estable.

## Ubicación del piloto

Ruta:

    python/gei_liquidaciones_piloto/

Archivos principales:

    generar_pdf.py
    pdf_desde_json_web_piloto.py
    config.json
    GeI_fox.png
    requirements.txt
    README.md

## Dependencias

El piloto usa ReportLab.

Archivo de dependencias:

    python/gei_liquidaciones_piloto/requirements.txt

Contenido mínimo:

    reportlab

## Crear entorno virtual local

Desde `gei-web/src`:

    python3 -m venv python/.venv
    python/.venv/bin/python -m pip install --upgrade pip
    python/.venv/bin/pip install -r python/gei_liquidaciones_piloto/requirements.txt

El entorno virtual local debe quedar ignorado por Git:

    python/.venv/

## Validar ReportLab

    python/.venv/bin/python - <<'PY2'
    import reportlab
    print("reportlab-ok", reportlab.Version)
    PY2

## Generar PDF piloto desde JSON

    python/.venv/bin/python \
      python/gei_liquidaciones_piloto/pdf_desde_json_web_piloto.py \
      storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto.json \
      --output storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto_reportlab_laravel_venv.pdf

## Validar PDF generado

    ls -lh storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto_reportlab_laravel_venv.pdf
    head -c 8 storage/app/private/liquidaciones/piloto/12020750010_202606/liquidacion_web_piloto_reportlab_laravel_venv.pdf
    echo

Resultado esperado:

    %PDF-1.4

## Validaciones realizadas

- El JSON intermedio es parseable.
- El total de ítems es `7712042.63`.
- La suma contable coincide con el total esperado.
- El PDF generado existe.
- El PDF tiene encabezado `%PDF-1.4`.
- El PDF pesa más de 0 bytes.
- No se consultó ninguna base de datos.
- No se tocó `db_gei`.
- No se tocó PostgreSQL 9.4.
- No se modificaron tablas heredadas.
- No se modificó `gei-liquidaciones-python`.

## Decisión

APTO_PARA_EJECUCION_LOCAL_LARAVEL.

El piloto ReportLab ya puede ejecutarse desde `gei-web` usando un entorno Python propio.
