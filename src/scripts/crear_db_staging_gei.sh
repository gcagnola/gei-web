#!/usr/bin/env bash
set -euo pipefail
trap 'estado=$?; echo "ERROR en la línea $LINENO (código $estado)." >&2; exit "$estado"' ERR

SCRIPT_VERSION="2026-07-29.3"
echo "crear_db_staging_gei.sh versión ${SCRIPT_VERSION}"
echo "Migración a staging por período de Laravel."
echo "pliqloc: se conservan las hojas adicionales (fecha + tipo + nro_liquidacion)."

# Crea/reutiliza gei_exploracion y carga un período almacenado por Laravel.
# No modifica los TXT ni el proyecto gei-liquidaciones-python.
#
# Uso recomendado (la carpeta debe llamarse AAAAMM):
#   ./crear_db_staging_gei.sh \
#     storage/app/private/liquidaciones/periodos/202607
#
# También admite la raíz de períodos y el período por separado:
#   ./crear_db_staging_gei.sh \
#     storage/app/private/liquidaciones/periodos 202607
#
# Variables opcionales:
#   GEI_DB=gei_exploracion
#   GEI_SCHEMA=cobol_staging
#   PGHOST=192.168.50.20
#   PGPORT=5431
#   PGUSER=postgres
#   GEI_RESULTADO_JSON=/ruta/resultado.json
#   GEI_PERMITIR_PERIODO_DISTINTO=1

GEI_DB="${GEI_DB:-gei_exploracion}"
GEI_SCHEMA="${GEI_SCHEMA:-cobol_staging}"
GEI_RESULTADO_JSON="${GEI_RESULTADO_JSON:-}"
GEI_PERMITIR_PERIODO_DISTINTO="${GEI_PERMITIR_PERIODO_DISTINTO:-0}"

if (( $# < 1 || $# > 2 )); then
    echo "Uso: $0 /ruta/periodos/AAAAMM" >&2
    echo "   o: $0 /ruta/periodos AAAAMM" >&2
    exit 1
fi

if (( $# == 2 )); then
    RAIZ_PERIODOS="${1%/}"
    PERIODO="$2"
    DIRECTORIO_PERIODO="${RAIZ_PERIODOS}/${PERIODO}"
else
    DIRECTORIO_PERIODO="${1%/}"
    PERIODO="$(basename "$DIRECTORIO_PERIODO")"
fi

if [[ ! "$PERIODO" =~ ^(19|20)[0-9]{2}(0[1-9]|1[0-2])$ ]]; then
    echo "Período no válido: $PERIODO. Se esperaba AAAAMM." >&2
    exit 1
fi

if [[ ! -d "$DIRECTORIO_PERIODO" ]]; then
    echo "No existe el directorio del período: $DIRECTORIO_PERIODO" >&2
    exit 1
fi

DIRECTORIO_COBOL="${DIRECTORIO_PERIODO}/cobol"
DIRECTORIO_LIQUIDACIONES="${DIRECTORIO_PERIODO}/liquidaciones"

for directorio in "$DIRECTORIO_COBOL" "$DIRECTORIO_LIQUIDACIONES"; do
    if [[ ! -d "$directorio" ]]; then
        echo "Falta el directorio requerido: $directorio" >&2
        exit 1
    fi
done

if [[ ! "$GEI_SCHEMA" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "GEI_SCHEMA contiene caracteres no válidos: $GEI_SCHEMA" >&2
    exit 1
fi
if [[ ! "$GEI_DB" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "GEI_DB contiene caracteres no válidos: $GEI_DB" >&2
    exit 1
fi

for comando in psql createdb python3 sha256sum find realpath; do
    command -v "$comando" >/dev/null 2>&1 || {
        echo "Falta el comando requerido: $comando" >&2
        exit 1
    }
done

buscar_archivo_en() {
    local directorio="$1"
    local nombre="$2"
    local encontrado
    encontrado="$(
        find "$directorio" -maxdepth 1 -type f -iname "$nombre" -print -quit
    )"
    if [[ -n "$encontrado" ]]; then
        printf '%s\n' "$encontrado"
        return 0
    fi
    return 1
}

PROPIETAR="$(buscar_archivo_en "$DIRECTORIO_COBOL" PROPIETAR.TXT || true)"
INQUILINO="$(buscar_archivo_en "$DIRECTORIO_COBOL" INQUILINO.TXT || true)"
CTACTEPRO="$(buscar_archivo_en "$DIRECTORIO_COBOL" CTACTEPRO.TXT || true)"
INQCTACTE="$(buscar_archivo_en "$DIRECTORIO_COBOL" INQCTACTE.TXT || true)"

PLIQLOC_SF="$(buscar_archivo_en "$DIRECTORIO_LIQUIDACIONES" pliqloc.sf.txt || true)"
PLIQLOC_ST="$(buscar_archivo_en "$DIRECTORIO_LIQUIDACIONES" pliqloc.st.txt || true)"

variables_requeridas=(
    PROPIETAR INQUILINO CTACTEPRO INQCTACTE
    PLIQLOC_SF PLIQLOC_ST
)

faltantes=()
for variable in "${variables_requeridas[@]}"; do
    if [[ -z "${!variable}" ]]; then
        faltantes+=("$variable")
    fi
done

if (( ${#faltantes[@]} > 0 )); then
    echo "No se encontraron todos los archivos requeridos." >&2
    printf '  - %s\n' "${faltantes[@]}" >&2
    echo "La migración requiere los 4 COBOL y pliqloc.sf.txt/pliqloc.st.txt." >&2
    exit 1
fi

echo "Período: $PERIODO"
echo "Origen:  $DIRECTORIO_PERIODO"

export PROPIETAR CTACTEPRO INQCTACTE
PERIODO_COBOL="$(
python3 <<'PY'
import os
from datetime import datetime
from pathlib import Path

specs = (
    ("CTACTEPRO.TXT", Path(os.environ["CTACTEPRO"]), 11),
    ("INQCTACTE.TXT", Path(os.environ["INQCTACTE"]), 11),
    ("PROPIETAR.TXT", Path(os.environ["PROPIETAR"]), 159),
)

latest_all = None
for name, path, offset in specs:
    latest = None
    for raw in path.read_bytes().splitlines():
        value = raw[offset:offset + 8].decode("ascii", errors="ignore")
        if value == "22200612" or len(value) != 8 or not value.isdigit():
            continue
        try:
            parsed = datetime.strptime(value, "%Y%m%d").date()
        except ValueError:
            continue
        if not 2000 <= parsed.year <= 2100:
            continue
        latest = parsed if latest is None or parsed > latest else latest
        latest_all = parsed if latest_all is None or parsed > latest_all else latest_all
    print(f"{name}: {latest.isoformat() if latest else 'sin fecha válida'}",
          file=os.sys.stderr)

if latest_all is None:
    raise SystemExit("No se encontró una fecha válida para detectar el período COBOL.")
print(latest_all.strftime("%Y%m"))
PY
)"

echo "Período detectado en COBOL: $PERIODO_COBOL"
if [[ "$PERIODO_COBOL" != "$PERIODO" && "$GEI_PERMITIR_PERIODO_DISTINTO" != "1" ]]; then
    echo "El período de la carpeta ($PERIODO) no coincide con el detectado en COBOL ($PERIODO_COBOL)." >&2
    echo "Revisá la carga. Sólo para una excepción controlada usá GEI_PERMITIR_PERIODO_DISTINTO=1." >&2
    exit 1
fi

if psql -d postgres -Atq --set=ON_ERROR_STOP=1 \
    -c 'SELECT datname FROM pg_database' | grep -Fxq -- "$GEI_DB"; then
    echo "La base \"$GEI_DB\" ya existe; se reutilizará."
else
    echo "Creando base \"$GEI_DB\"..."
    createdb "$GEI_DB"
fi

psql -d "$GEI_DB" --set=ON_ERROR_STOP=1 <<SQL
CREATE SCHEMA IF NOT EXISTS "$GEI_SCHEMA";

CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".archivos_importados (
    id                  bigserial PRIMARY KEY,
    nombre_archivo      text NOT NULL,
    ruta_archivo        text NOT NULL,
    sha256_archivo      text NOT NULL,
    tamano_bytes        bigint NOT NULL,
    cantidad_registros  bigint NOT NULL,
    importado_en        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (nombre_archivo, sha256_archivo)
);

CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".migraciones_periodos (
    id                  bigserial PRIMARY KEY,
    periodo             char(6) NOT NULL CHECK (periodo ~ '^(19|20)[0-9]{2}(0[1-9]|1[0-2])$'),
    ruta_periodo        text NOT NULL,
    version_script      text NOT NULL,
    estado              text NOT NULL CHECK (estado IN ('EJECUTANDO', 'OK', 'ERROR')),
    iniciada_en         timestamptz NOT NULL DEFAULT now(),
    finalizada_en       timestamptz,
    mensaje             text,
    archivos_encontrados integer NOT NULL DEFAULT 0,
    registros_cargados  bigint NOT NULL DEFAULT 0,
    registros_omitidos  bigint NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS migraciones_periodos_periodo_idx
    ON "$GEI_SCHEMA".migraciones_periodos (periodo, iniciada_en DESC);

CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".migraciones_periodos_archivos (
    migracion_id        bigint NOT NULL
                        REFERENCES "$GEI_SCHEMA".migraciones_periodos(id)
                        ON DELETE CASCADE,
    archivo_id          bigint NOT NULL
                        REFERENCES "$GEI_SCHEMA".archivos_importados(id),
    grupo               text NOT NULL CHECK (grupo IN ('cobol', 'liquidaciones')),
    procesado_en_tabla  text,
    PRIMARY KEY (migracion_id, archivo_id, grupo)
);

CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".propietar (
    id bigserial PRIMARY KEY,
    archivo_id bigint NOT NULL REFERENCES "$GEI_SCHEMA".archivos_importados(id),
    numero_linea bigint NOT NULL, nro_cta_prop text, nombre_prop text, domicilio_prop text,
    encot_prop text, localidad_prop text, provincia_prop text, telefono_1 text, telefono_2 text,
    liquida_sin_resumen text, comision_impuestos text, comision_administracion text,
    forma_pago text, sub_forma_pago text, liquidar text, nro_ultima_liquidacion text,
    fecha_ultima_liquidacion text, cuenta_deposito text, reservados text, tipo_iva text,
    nro_iva text, marca_sucursal text, relleno_cobol text, linea_original text NOT NULL,
    longitud_linea integer NOT NULL, sha256_registro text NOT NULL,
    UNIQUE (archivo_id, numero_linea)
);

CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".inquilino (
    id bigserial PRIMARY KEY,
    archivo_id bigint NOT NULL REFERENCES "$GEI_SCHEMA".archivos_importados(id),
    numero_linea bigint NOT NULL, cta_inquilino text, cta_propietario text, nombre_inquilino text,
    direccion_finca text, fecha_contrato text, fecha_vencimiento text, fecha_primer_ajuste text,
    plazo text, indice text, tipo_ajuste text, cuota_1 text, cuota_2 text, marca_baja text,
    fecha_baja text, nro_liquidacion text, marca_intimacion text, telefono_particular text,
    telefono_laboral text,
    ajuste_1_fecha text, ajuste_1_porcentaje text, ajuste_2_fecha text, ajuste_2_porcentaje text,
    ajuste_3_fecha text, ajuste_3_porcentaje text, ajuste_4_fecha text, ajuste_4_porcentaje text,
    ajuste_5_fecha text, ajuste_5_porcentaje text, ajuste_6_fecha text, ajuste_6_porcentaje text,
    ajuste_7_fecha text, ajuste_7_porcentaje text, ajuste_8_fecha text, ajuste_8_porcentaje text,
    administracion_responsable text, destino text, penal_porcentaje text, penal_importe text,
    impuesto_porcentaje_1 text, impuesto_porcentaje_2 text, impuesto_porcentaje_3 text,
    impuesto_porcentaje_4 text, impuesto_porcentaje_5 text, impuesto_porcentaje_6 text,
    fecha_celebracion_redefine text, copias_contrato_redefine text,
    reservado_impuestos_redefine text, comision_anterior text, fecha_inicio text,
    alquiler_inicial text, reparacion text, dias_reparacion text, tipo_documento text,
    nro_documento text, domicilio_legal text, encot_legal text, localidad_legal text,
    provincia_legal text, partida_1 text, partida_2 text, partida_3 text, partida_4 text,
    partida_5 text, partida_6 text, acumulado_penalidad text, fecha_juicio text,
    abogado text, plazo_dias text, tipo_iva text, nro_iva text, cuota_2_dolar text,
    identificador_cochera text, marca_intimacion_1 text, comision_importe text,
    filler text, relleno_cobol text, linea_original text NOT NULL,
    longitud_linea integer NOT NULL, sha256_registro text NOT NULL,
    UNIQUE (archivo_id, numero_linea)
);

CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".ctactepro (
    id bigserial PRIMARY KEY,
    archivo_id bigint NOT NULL REFERENCES "$GEI_SCHEMA".archivos_importados(id),
    numero_linea bigint NOT NULL, cuenta text, fecha text, codigo text, numero text,
    importe text, descripcion text, inquilino text, liquidado text, iva text,
    no_iva text, filler text, linea_original text NOT NULL, longitud_linea integer NOT NULL,
    sha256_registro text NOT NULL, UNIQUE (archivo_id, numero_linea)
);

CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".inqctacte (
    id bigserial PRIMARY KEY,
    archivo_id bigint NOT NULL REFERENCES "$GEI_SCHEMA".archivos_importados(id),
    numero_linea bigint NOT NULL, cuenta text, fecha text, codigo text, numero text,
    fecha_vencimiento text, importe text, importe_penalidad text, importe_abonado text,
    descripcion text, liquidado text, filler text, iva text, no_iva text,
    linea_original text NOT NULL, longitud_linea integer NOT NULL, sha256_registro text NOT NULL,
    UNIQUE (archivo_id, numero_linea)
);

-- Nunca se eliminan: cada período conserva sus archivos y registros.
CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".pliqloc_sf (
    id bigserial PRIMARY KEY,
    archivo_id bigint NOT NULL REFERENCES "$GEI_SCHEMA".archivos_importados(id),
    numero_linea bigint NOT NULL,
    fecha date NOT NULL,
    tipo char(1) NOT NULL CHECK (tipo IN ('A', 'B')),
    nro_liquidacion text NOT NULL,
    nro_cuenta text NOT NULL DEFAULT '',
    propietario text NOT NULL DEFAULT '',
    iva text NOT NULL DEFAULT '',
    cuit text NOT NULL DEFAULT '',
    total_liquidacion text NOT NULL DEFAULT '',
    UNIQUE (archivo_id, numero_linea)
);

CREATE TABLE IF NOT EXISTS "$GEI_SCHEMA".pliqloc_st (
    id bigserial PRIMARY KEY,
    archivo_id bigint NOT NULL REFERENCES "$GEI_SCHEMA".archivos_importados(id),
    numero_linea bigint NOT NULL,
    fecha date NOT NULL,
    tipo char(1) NOT NULL CHECK (tipo IN ('A', 'B')),
    nro_liquidacion text NOT NULL,
    nro_cuenta text NOT NULL DEFAULT '',
    propietario text NOT NULL DEFAULT '',
    iva text NOT NULL DEFAULT '',
    cuit text NOT NULL DEFAULT '',
    total_liquidacion text NOT NULL DEFAULT '',
    UNIQUE (archivo_id, numero_linea)
);
SQL

DIRECTORIO_PERIODO_REAL="$(realpath "$DIRECTORIO_PERIODO")"
MIGRACION_ID="$(
    psql -d "$GEI_DB" -Atq --set=ON_ERROR_STOP=1 \
        --set=periodo="$PERIODO" \
        --set=ruta_periodo="$DIRECTORIO_PERIODO_REAL" \
        --set=version_script="$SCRIPT_VERSION" <<SQL
INSERT INTO "$GEI_SCHEMA".migraciones_periodos
    (periodo, ruta_periodo, version_script, estado)
VALUES
    (:'periodo', :'ruta_periodo', :'version_script', 'EJECUTANDO')
RETURNING id;
SQL
)"

if [[ ! "$MIGRACION_ID" =~ ^[0-9]+$ ]]; then
    echo "No se pudo registrar la migración del período $PERIODO." >&2
    exit 1
fi

registrar_error() {
    local estado="$1"
    local linea="$2"
    local mensaje="ERROR en la línea ${linea} (código ${estado})."
    psql -d "$GEI_DB" -q --set=ON_ERROR_STOP=1 \
        --set=migracion_id="$MIGRACION_ID" \
        --set=mensaje="$mensaje" <<SQL_ERR || true
UPDATE "$GEI_SCHEMA".migraciones_periodos
SET estado = 'ERROR',
    finalizada_en = now(),
    mensaje = :'mensaje'
WHERE id = :'migracion_id'::bigint;
SQL_ERR
    MIGRACION_FINALIZADA=1
    echo "$mensaje" >&2
    exit "$estado"
}
trap 'registrar_error "$?" "$LINENO"' ERR

TMP_DIR="$(mktemp -d)"
MIGRACION_FINALIZADA=0
finalizar_script() {
    local estado=$?
    rm -rf "$TMP_DIR"
    if (( estado != 0 && MIGRACION_FINALIZADA == 0 )); then
        psql -d "$GEI_DB" -q --set=ON_ERROR_STOP=1 \
            --set=migracion_id="$MIGRACION_ID" <<SQL_EXIT || true
UPDATE "$GEI_SCHEMA".migraciones_periodos
SET estado = 'ERROR',
    finalizada_en = now(),
    mensaje = COALESCE(mensaje, 'La ejecución terminó antes de completar la migración.')
WHERE id = :'migracion_id'::bigint
  AND estado = 'EJECUTANDO';
SQL_EXIT
    fi
    exit "$estado"
}
trap finalizar_script EXIT

tablas=(
    propietar inquilino ctactepro inqctacte pliqloc_sf pliqloc_st
)
archivos=(
    "$PROPIETAR" "$INQUILINO" "$CTACTEPRO" "$INQCTACTE"
    "$PLIQLOC_SF" "$PLIQLOC_ST"
)
grupos=(
    cobol cobol cobol cobol
    liquidaciones liquidaciones
)

TOTAL_REGISTROS_CARGADOS=0
TOTAL_REGISTROS_OMITIDOS=0

columnas_propietar='archivo_id, numero_linea, nro_cta_prop, nombre_prop, domicilio_prop, encot_prop, localidad_prop, provincia_prop, telefono_1, telefono_2, liquida_sin_resumen, comision_impuestos, comision_administracion, forma_pago, sub_forma_pago, liquidar, nro_ultima_liquidacion, fecha_ultima_liquidacion, cuenta_deposito, reservados, tipo_iva, nro_iva, marca_sucursal, relleno_cobol, linea_original, longitud_linea, sha256_registro'
columnas_inquilino='archivo_id, numero_linea, cta_inquilino, cta_propietario, nombre_inquilino, direccion_finca, fecha_contrato, fecha_vencimiento, fecha_primer_ajuste, plazo, indice, tipo_ajuste, cuota_1, cuota_2, marca_baja, fecha_baja, nro_liquidacion, marca_intimacion, telefono_particular, telefono_laboral, ajuste_1_fecha, ajuste_1_porcentaje, ajuste_2_fecha, ajuste_2_porcentaje, ajuste_3_fecha, ajuste_3_porcentaje, ajuste_4_fecha, ajuste_4_porcentaje, ajuste_5_fecha, ajuste_5_porcentaje, ajuste_6_fecha, ajuste_6_porcentaje, ajuste_7_fecha, ajuste_7_porcentaje, ajuste_8_fecha, ajuste_8_porcentaje, administracion_responsable, destino, penal_porcentaje, penal_importe, impuesto_porcentaje_1, impuesto_porcentaje_2, impuesto_porcentaje_3, impuesto_porcentaje_4, impuesto_porcentaje_5, impuesto_porcentaje_6, fecha_celebracion_redefine, copias_contrato_redefine, reservado_impuestos_redefine, comision_anterior, fecha_inicio, alquiler_inicial, reparacion, dias_reparacion, tipo_documento, nro_documento, domicilio_legal, encot_legal, localidad_legal, provincia_legal, partida_1, partida_2, partida_3, partida_4, partida_5, partida_6, acumulado_penalidad, fecha_juicio, abogado, plazo_dias, tipo_iva, nro_iva, cuota_2_dolar, identificador_cochera, marca_intimacion_1, comision_importe, filler, relleno_cobol, linea_original, longitud_linea, sha256_registro'
columnas_ctactepro='archivo_id, numero_linea, cuenta, fecha, codigo, numero, importe, descripcion, inquilino, liquidado, iva, no_iva, filler, linea_original, longitud_linea, sha256_registro'
columnas_inqctacte='archivo_id, numero_linea, cuenta, fecha, codigo, numero, fecha_vencimiento, importe, importe_penalidad, importe_abonado, descripcion, liquidado, filler, iva, no_iva, linea_original, longitud_linea, sha256_registro'
columnas_pliqloc='archivo_id, numero_linea, fecha, tipo, nro_liquidacion, nro_cuenta, propietario, iva, cuit, total_liquidacion'

for indice in "${!tablas[@]}"; do
    tabla="${tablas[$indice]}"
    archivo_fuente="${archivos[$indice]}"
    grupo="${grupos[$indice]}"
    metadata_path="$TMP_DIR/archivo_${indice}.tsv"
    registros_path="$TMP_DIR/registros_${indice}.tsv"

    export TABLA_ACTUAL="$tabla" ARCHIVO_ACTUAL="$archivo_fuente" METADATA_ACTUAL="$metadata_path"
    python3 <<'PY'
import csv
import hashlib
import os
import re
from pathlib import Path

source = Path(os.environ["ARCHIVO_ACTUAL"])
data = source.read_bytes()
if os.environ["TABLA_ACTUAL"].startswith("pliqloc_"):
    cantidad_registros = sum(
        1 for line in data.splitlines()
        if re.match(rb"^\d{2}/\d{2}/\d{4}", line)
    )
else:
    cantidad_registros = len(data.splitlines())
with Path(os.environ["METADATA_ACTUAL"]).open("w", encoding="utf-8", newline="") as out:
    csv.writer(out, delimiter="\t", lineterminator="\n").writerow(
        [source.name, str(source.resolve()), hashlib.sha256(data).hexdigest(),
         len(data), cantidad_registros]
    )
PY

    echo "Registrando archivo de origen: $(basename "$archivo_fuente")..."
    psql -d "$GEI_DB" --set=ON_ERROR_STOP=1 <<SQL
CREATE TEMP TABLE archivo_actual (
    nombre_archivo text, ruta_archivo text, sha256_archivo text,
    tamano_bytes bigint, cantidad_registros bigint
);
\copy archivo_actual (nombre_archivo, ruta_archivo, sha256_archivo, tamano_bytes, cantidad_registros) FROM '$metadata_path' WITH (FORMAT csv, DELIMITER E'\t')
INSERT INTO "$GEI_SCHEMA".archivos_importados
    (nombre_archivo, ruta_archivo, sha256_archivo, tamano_bytes, cantidad_registros)
SELECT nombre_archivo, ruta_archivo, sha256_archivo, tamano_bytes, cantidad_registros
FROM archivo_actual
ON CONFLICT (nombre_archivo, sha256_archivo)
DO UPDATE SET
    ruta_archivo = EXCLUDED.ruta_archivo,
    tamano_bytes = EXCLUDED.tamano_bytes,
    cantidad_registros = EXCLUDED.cantidad_registros;
SQL

    archivo_hash="$(sha256sum "$archivo_fuente" | cut -d' ' -f1)"
    archivo_nombre="$(basename "$archivo_fuente")"
    archivo_nombre_sql="${archivo_nombre//\'/\'\'}"
    archivo_id="$(
        psql -d "$GEI_DB" -Atq --set=ON_ERROR_STOP=1 \
            -c "SELECT id FROM \"$GEI_SCHEMA\".archivos_importados WHERE sha256_archivo = '$archivo_hash' AND nombre_archivo = '$archivo_nombre_sql' ORDER BY id DESC LIMIT 1"
    )"

    psql -d "$GEI_DB" -q --set=ON_ERROR_STOP=1 \
        --set=migracion_id="$MIGRACION_ID" \
        --set=archivo_id="$archivo_id" \
        --set=grupo="$grupo" \
        --set=tabla="$tabla" <<SQL
INSERT INTO "$GEI_SCHEMA".migraciones_periodos_archivos
    (migracion_id, archivo_id, grupo, procesado_en_tabla)
VALUES
    (
        :'migracion_id'::bigint,
        :'archivo_id'::bigint,
        :'grupo',
        NULLIF(:'tabla', '')
    )
ON CONFLICT (migracion_id, archivo_id, grupo)
DO UPDATE SET procesado_en_tabla = EXCLUDED.procesado_en_tabla;
SQL

    if [[ -z "$tabla" ]]; then
        echo "OK: $(basename "$archivo_fuente") registrado para el período; no requiere tabla staging."
        continue
    fi

    cantidad_esperada="$(
        psql -d "$GEI_DB" -Atq --set=ON_ERROR_STOP=1 \
            -c "SELECT cantidad_registros FROM \"$GEI_SCHEMA\".archivos_importados WHERE id = $archivo_id"
    )"
    cantidad_actual="$(
        psql -d "$GEI_DB" -Atq --set=ON_ERROR_STOP=1 \
            -c "SELECT count(*) FROM \"$GEI_SCHEMA\".\"$tabla\" WHERE archivo_id = $archivo_id"
    )"

    if [[ "$cantidad_actual" == "$cantidad_esperada" ]]; then
        echo "OK: $tabla ya estaba completo ($cantidad_actual registros); se omite."
        TOTAL_REGISTROS_OMITIDOS=$((TOTAL_REGISTROS_OMITIDOS + cantidad_actual))
        continue
    fi

    export TABLA_ACTUAL="$tabla" ARCHIVO_ID="$archivo_id" SALIDA_TSV="$registros_path"
    python3 <<'PY'
import csv
import hashlib
import os
import re
from datetime import datetime
from decimal import Decimal, InvalidOperation
from pathlib import Path

table = os.environ["TABLA_ACTUAL"]
source = Path(os.environ["ARCHIVO_ACTUAL"])
file_id = int(os.environ["ARCHIVO_ID"])
target = Path(os.environ["SALIDA_TSV"])
raw_lines = source.read_bytes().splitlines()

fixed_layouts = {
    "propietar": [
        ("nro_cta_prop", 11), ("nombre_prop", 35), ("domicilio_prop", 30),
        ("encot_prop", 4), ("localidad_prop", 26), ("provincia_prop", 10),
        ("telefono_1", 14), ("telefono_2", 14), ("liquida_sin_resumen", 1),
        ("comision_impuestos", 3), ("comision_administracion", 3),
        ("forma_pago", 2), ("sub_forma_pago", 1), ("liquidar", 1),
        ("nro_ultima_liquidacion", 4), ("fecha_ultima_liquidacion", 8),
        ("cuenta_deposito", 14), ("reservados", 3), ("tipo_iva", 1),
        ("nro_iva", 12), ("marca_sucursal", 1), ("relleno_cobol", 2),
    ],
    "inquilino": [
        ("cta_inquilino", 11), ("cta_propietario", 11), ("nombre_inquilino", 35),
        ("direccion_finca", 35), ("fecha_contrato", 8), ("fecha_vencimiento", 8),
        ("fecha_primer_ajuste", 8), ("plazo", 3), ("indice", 3), ("tipo_ajuste", 2),
        ("cuota_1", 10), ("cuota_2", 10), ("marca_baja", 1), ("fecha_baja", 8),
        ("nro_liquidacion", 2), ("marca_intimacion", 1), ("telefono_particular", 14),
        ("telefono_laboral", 14),
        *[(field, width) for n in range(1, 9)
          for field, width in ((f"ajuste_{n}_fecha", 8), (f"ajuste_{n}_porcentaje", 5))],
        ("administracion_responsable", 1), ("destino", 3),
        ("penal_porcentaje", 3), ("penal_importe", 10),
        *[(f"impuesto_porcentaje_{n}", 3) for n in range(1, 7)],
        ("comision_anterior", 2), ("fecha_inicio", 8), ("alquiler_inicial", 10),
        ("reparacion", 1), ("dias_reparacion", 3), ("tipo_documento", 1),
        ("nro_documento", 9), ("domicilio_legal", 35), ("encot_legal", 4),
        ("localidad_legal", 26), ("provincia_legal", 10),
        *[(f"partida_{n}", 12) for n in range(1, 7)],
        ("acumulado_penalidad", 10), ("fecha_juicio", 8), ("abogado", 2),
        ("plazo_dias", 2), ("tipo_iva", 1), ("nro_iva", 12),
        ("cuota_2_dolar", 8), ("identificador_cochera", 2),
        ("marca_intimacion_1", 1), ("comision_importe", 4),
        ("filler", 5), ("relleno_cobol", 1),
    ],
    "ctactepro": [
        ("cuenta", 11), ("fecha", 8), ("codigo", 2), ("numero", 6),
        ("importe", 12), ("descripcion", 40), ("inquilino", 11),
        ("liquidado", 1), ("iva", 10), ("no_iva", 10), ("filler", 1),
    ],
    "inqctacte": [
        ("cuenta", 11), ("fecha", 8), ("codigo", 2), ("numero", 6),
        ("fecha_vencimiento", 8), ("importe", 12), ("importe_penalidad", 12),
        ("importe_abonado", 12), ("descripcion", 40), ("liquidado", 1),
        ("filler", 2), ("iva", 10), ("no_iva", 10),
    ],
}

def fixed_row(number, raw):
    text = raw.decode("cp1252")
    layout = fixed_layouts[table]
    expected = sum(width for _, width in layout)
    original_length = len(text)
    if original_length > expected:
        raise SystemExit(
            f"{source.name}, línea {number}: longitud {original_length}; máxima esperada {expected}"
        )
    # Los TXT acumulativos pueden terminar con un último registro recortado.
    # Se completa sólo para separar los campos; linea_original y longitud_linea
    # conservan exactamente lo recibido.
    parse_text = text.ljust(expected)
    pos = 0
    values = []
    for _, width in layout:
        values.append(parse_text[pos:pos + width])
        pos += width
    if table == "inquilino":
        tax_index = next(i for i, item in enumerate(layout)
                         if item[0] == "impuesto_porcentaje_1")
        tax_start = sum(width for _, width in layout[:tax_index])
        redefined = parse_text[tax_start:tax_start + 18]
        insert_at = next(i for i, item in enumerate(layout)
                         if item[0] == "comision_anterior")
        values[insert_at:insert_at] = [
            redefined[:8], redefined[8:10], redefined[10:18]
        ]
    return [file_id, number, *values, text, original_length, hashlib.sha256(raw).hexdigest()]

def pliqloc_row(number, raw):
    text = raw.decode("cp1252")
    if not re.match(r"^\d{2}/\d{2}/\d{4}", text):
        return None

    # Se reconoce primero el prefijo común a todas las hojas. Algunas líneas
    # terminan aquí porque representan una hoja adicional de la liquidación.
    encabezado = re.match(
        r"^(?P<fecha>\d{2}/\d{2}/\d{4})\s+"
        r"(?P<tipo>[AB])\s+"
        r"(?P<numero>\d{8})(?P<resto>.*)$",
        text,
    )
    if encabezado is None:
        raise SystemExit(
            f"{source.name}, línea {number}: fecha, tipo o número de liquidación inválido"
        )

    fecha_texto = encabezado.group("fecha")
    tipo = encabezado.group("tipo")
    nro_liquidacion = encabezado.group("numero")
    resto = encabezado.group("resto")

    try:
        fecha = datetime.strptime(fecha_texto, "%d/%m/%Y").date().isoformat()
    except ValueError as error:
        raise SystemExit(
            f"{source.name}, línea {number}: fecha inválida {fecha_texto!r}: {error}"
        ) from error

    # Una línea que sólo contiene fecha, tipo y número indica una hoja
    # adicional de esa misma liquidación. Debe conservarse; las columnas
    # que sólo aparecen en la última hoja quedan como cadenas vacías.
    if not resto.strip():
        return [
            file_id, number, fecha, tipo, nro_liquidacion,
            "", "", "", "", ""
        ]

    nro_cuenta = text[25:38].strip()
    propietario = text[40:75].strip()
    iva = text[75:100].strip()
    cuit = text[100:111].strip()
    total_texto = text[111:].strip()

    # Las líneas completas deben respetar el formato del listado. No se
    # descartan silenciosamente porque toda línea iniciada con fecha representa
    # una hoja de liquidación.
    if (
        not re.fullmatch(r"\d{4}/\d{5}/\d{2}", nro_cuenta)
        or not propietario
        or not iva
        or not re.fullmatch(r"(?:\d{11}|0)", cuit)
        or not re.fullmatch(r"[\d.]+,\d{2}(?:DB)?", total_texto, re.I)
    ):
        raise SystemExit(
            f"{source.name}, línea {number}: formato de liquidación inválido"
        )

    es_deudor = total_texto.upper().endswith("DB")
    if es_deudor:
        total_texto = total_texto[:-2].strip()
    try:
        total = Decimal(total_texto.replace(".", "").replace(",", "."))
    except InvalidOperation as error:
        raise SystemExit(
            f"{source.name}, línea {number}: importe inválido {total_texto!r}"
        ) from error
    if es_deudor:
        total = -abs(total)

    return [
        file_id, number, fecha, tipo, nro_liquidacion, nro_cuenta,
        propietario, iva, cuit, format(total, ".2f")
    ]

if table in fixed_layouts:
    rows = [fixed_row(number, raw) for number, raw in enumerate(raw_lines, 1)]
elif table.startswith("pliqloc_"):
    rows = [
        row for number, raw in enumerate(raw_lines, 1)
        if (row := pliqloc_row(number, raw)) is not None
    ]
else:
    raise SystemExit(f"Tabla no contemplada: {table}")

with target.open("w", encoding="utf-8", newline="") as out:
    writer = csv.writer(out, delimiter="\t", lineterminator="\n")
    # None se representa exclusivamente como \N.
    for row in rows:
        writer.writerow([
            r"\N" if value is None else value
            for value in row
        ])
PY

    case "$tabla" in
        propietar) columnas="$columnas_propietar" ;;
        inquilino) columnas="$columnas_inquilino" ;;
        ctactepro) columnas="$columnas_ctactepro" ;;
        inqctacte) columnas="$columnas_inqctacte" ;;
        pliqloc_sf|pliqloc_st) columnas="$columnas_pliqloc" ;;
        *) echo "Tabla no contemplada: $tabla" >&2; exit 1 ;;
    esac

    if [[ ! -s "$registros_path" ]]; then
        echo "No se generaron registros para $tabla: $registros_path" >&2
        exit 1
    fi

    echo "Cargando $tabla (archivo_id=$archivo_id)..."
    psql -d "$GEI_DB" --set=ON_ERROR_STOP=1 <<SQL
BEGIN;
DELETE FROM "$GEI_SCHEMA"."$tabla" WHERE archivo_id = $archivo_id;
\copy "$GEI_SCHEMA"."$tabla" ($columnas) FROM '$registros_path' WITH (FORMAT csv, DELIMITER E'\t', NULL '\N')
COMMIT;
SQL

    cantidad_cargada="$(
        psql -d "$GEI_DB" -Atq --set=ON_ERROR_STOP=1 \
            -c "SELECT count(*) FROM \"$GEI_SCHEMA\".\"$tabla\" WHERE archivo_id = $archivo_id"
    )"
    if [[ "$cantidad_cargada" != "$cantidad_esperada" ]]; then
        echo "Carga incompleta en $tabla: $cantidad_cargada de $cantidad_esperada." >&2
        exit 1
    fi
    TOTAL_REGISTROS_CARGADOS=$((TOTAL_REGISTROS_CARGADOS + cantidad_cargada))
    echo "OK: $tabla -> $cantidad_cargada registros."
done

ARCHIVOS_ENCONTRADOS="${#archivos[@]}"
psql -d "$GEI_DB" -q --set=ON_ERROR_STOP=1 \
    --set=migracion_id="$MIGRACION_ID" \
    --set=archivos_encontrados="$ARCHIVOS_ENCONTRADOS" \
    --set=registros_cargados="$TOTAL_REGISTROS_CARGADOS" \
    --set=registros_omitidos="$TOTAL_REGISTROS_OMITIDOS" <<SQL
UPDATE "$GEI_SCHEMA".migraciones_periodos
SET estado = 'OK',
    finalizada_en = now(),
    mensaje = 'Migración del período completada.',
    archivos_encontrados = :'archivos_encontrados'::integer,
    registros_cargados = :'registros_cargados'::bigint,
    registros_omitidos = :'registros_omitidos'::bigint
WHERE id = :'migracion_id'::bigint;
SQL
MIGRACION_FINALIZADA=1

echo
echo "Carga del período $PERIODO finalizada en ${GEI_DB}.${GEI_SCHEMA}"
psql -d "$GEI_DB" --set=ON_ERROR_STOP=1 -P pager=off -c "
SELECT tabla, registros
FROM (
    SELECT 'propietar' tabla, count(*) registros FROM \"$GEI_SCHEMA\".propietar
    UNION ALL SELECT 'inquilino', count(*) FROM \"$GEI_SCHEMA\".inquilino
    UNION ALL SELECT 'ctactepro', count(*) FROM \"$GEI_SCHEMA\".ctactepro
    UNION ALL SELECT 'inqctacte', count(*) FROM \"$GEI_SCHEMA\".inqctacte
    UNION ALL SELECT 'pliqloc_sf', count(*) FROM \"$GEI_SCHEMA\".pliqloc_sf
    UNION ALL SELECT 'pliqloc_st', count(*) FROM \"$GEI_SCHEMA\".pliqloc_st
) resumen
ORDER BY tabla;"

export PERIODO MIGRACION_ID ARCHIVOS_ENCONTRADOS
export TOTAL_REGISTROS_CARGADOS TOTAL_REGISTROS_OMITIDOS GEI_RESULTADO_JSON
RESULTADO_JSON="$(
python3 <<'PY'
import json
import os

result = {
    "ok": True,
    "periodo": os.environ["PERIODO"],
    "migracion_id": int(os.environ["MIGRACION_ID"]),
    "archivos_encontrados": int(os.environ["ARCHIVOS_ENCONTRADOS"]),
    "registros_cargados": int(os.environ["TOTAL_REGISTROS_CARGADOS"]),
    "registros_omitidos": int(os.environ["TOTAL_REGISTROS_OMITIDOS"]),
}
output = os.environ.get("GEI_RESULTADO_JSON", "")
if output:
    with open(output, "w", encoding="utf-8") as target:
        json.dump(result, target, ensure_ascii=False, indent=2)
        target.write("\n")
print(json.dumps(result, ensure_ascii=False, separators=(",", ":")))
PY
)"
echo "RESULTADO_JSON=$RESULTADO_JSON"
