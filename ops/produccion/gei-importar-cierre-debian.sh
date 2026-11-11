#!/usr/bin/env bash
# Debian: procesar evento de cierre COBOL solamente cuando todos los archivos esten transferidos.
# Modo produccion: fotografía diaria aislada + publicación operativa transaccional.
# Los modos integridad/prueba se conservan como diagnósticos.
set -Eeuo pipefail
umask 077
export LC_ALL=C
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

[[ "$(hostname)" == gei-produccion ]] || { echo 'ERROR: solamente gei-produccion' >&2; exit 2; }
[[ "$(id -u)" == 0 ]] || { echo 'ERROR: ejecutar como root' >&2; exit 2; }

ORIGEN=/srv/backups-fedora/santa-fe/home/alqui/exportados_postgresl
SENIAL="$ORIGEN/.gei-importar-pendiente"
EN_PROCESO="$ORIGEN/.gei-importar-procesando"
CONF=/etc/gei/importacion-cobol.conf

exec 9>/var/lock/gei-importar-cierre.lock
flock -n 9 || { echo 'ERROR: otra importacion esta ejecutandose'; exit 3; }
[[ -f "$SENIAL" && ! -L "$SENIAL" ]] || { echo 'No hay señal de lote pendiente'; exit 0; }
mv -f -- "$SENIAL" "$EN_PROCESO"
WORK=''
limpieza() {
  rc=$?
  if [[ -n "$WORK" && -d "$WORK" ]]; then rm -rf -- "$WORK"; fi
  if [[ -n "${CONTENEDOR_DIR:-}" && "$CONTENEDOR_DIR" == /tmp/gei-cobol-diario-* ]]; then
    docker exec gei-app rm -rf -- "$CONTENEDOR_DIR" 2>/dev/null || true
  fi
  if (( rc != 0 )) && [[ -f "$EN_PROCESO" ]]; then
    mv -f -- "$EN_PROCESO" "$ORIGEN/.gei-importar-fallido"
    echo "ERROR_IMPORTACION $(date -Is) codigo=$rc" >&2
  fi
}
trap limpieza EXIT

[[ -f "$CONF" && "$(stat -c %u "$CONF")" == 0 ]] || { echo "ERROR: falta config root-owned $CONF" >&2; exit 2; }
# shellcheck source=/dev/null
source "$CONF"
: "${GEI_PG_MODE:=integridad}"
: "${GEI_BASE_DIR:=/opt/gei-cobol-sync/base-202609}"
: "${GEI_TOOLS_DIR:=/opt/gei-cobol-sync/herramientas}"
[[ "$GEI_PG_MODE" == integridad || "$GEI_PG_MODE" == prueba || "$GEI_PG_MODE" == produccion ]] || { echo 'ERROR: GEI_PG_MODE invalido'; exit 2; }

cd "$ORIGEN"
for f in PROPIETAR.TXT INQUILINO.TXT CTACTEPRO.TXT INQCTACTE.TXT \
  GARANTE.TXT INQTASAINT INQIVA INQREDONDE INQTABCODI INQPARIDAD \
  SHA256SUMS EXPORTACION_OK; do
  [[ -f "$f" && ! -L "$f" && -s "$f" ]] || { echo "ERROR: falta $f" >&2; exit 4; }
done
[[ "$(grep '^servidor=' EXPORTACION_OK)" == 'servidor=gei-c-central' ]] || { echo 'ERROR: servidor incorrecto' >&2; exit 4; }
[[ "$(grep '^archivos=' EXPORTACION_OK)" == 'archivos=10' ]] || { echo 'ERROR: se esperaban 10 archivos' >&2; exit 4; }
FECHA=$(sed -n 's/^fecha=\([0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\}\)T.*/\1/p' EXPORTACION_OK)
[[ "$FECHA" == "$(date +%F)" ]] || { echo "ERROR: lote no es de hoy: $FECHA" >&2; exit 4; }
[[ "$(sed -n 's/^fecha=//p' "$EN_PROCESO")" == "$FECHA" ]] || { echo 'ERROR: fecha de senial no coincide' >&2; exit 4; }
ACTUAL_SHA=$(sha256sum EXPORTACION_OK | awk '{print $1}')
[[ "$(sed -n 's/^sha256=//p' "$EN_PROCESO")" == "$ACTUAL_SHA" ]] || { echo 'ERROR: checksum de senial no coincide' >&2; exit 4; }
sha256sum -c SHA256SUMS
printf 'LOTE_VALIDADO_OK fecha=%s\n' "$FECHA"

if [[ "$GEI_PG_MODE" == produccion ]]; then
  # Solo después del cierre completo y validado. No se requieren TXT de ST:
  # ambos prefijos están incluidos en estos mismos cuatro archivos.
  CONTENEDOR_DIR="/tmp/gei-cobol-diario-${FECHA}-$$"
  PERIODO="${FECHA:0:4}${FECHA:5:2}"
  DESTINO_CONTENEDOR="$CONTENEDOR_DIR/$PERIODO"
  docker exec gei-app mkdir -p "$DESTINO_CONTENEDOR/cobol"
  for f in PROPIETAR.TXT INQUILINO.TXT CTACTEPRO.TXT INQCTACTE.TXT; do
    docker cp "$ORIGEN/$f" "gei-app:$DESTINO_CONTENEDOR/cobol/$f"
    # Verificación de copia intercontenedor antes de comenzar la carga.
    SHA_ORIGEN="$(sha256sum "$ORIGEN/$f" | cut -d ' ' -f 1)"
    SHA_DESTINO="$(docker exec gei-app sha256sum "$DESTINO_CONTENEDOR/cobol/$f" | cut -d ' ' -f 1)"
    [[ "$SHA_ORIGEN" == "$SHA_DESTINO" ]] || { echo "ERROR: copia distinta $f" >&2; exit 5; }
  done
  # Los parametros GIMB38 no deben bloquear la publicacion del ledger.
  # Quedan versionados por cierre y se habilitan solo con marcador verificado.
  if ! (
    set -Eeuo pipefail
    APP_BASE="$(docker exec gei-app php -r 'echo dirname(realpath("artisan"));')" || exit 1
    [[ "$APP_BASE" == /* && "$APP_BASE" != / ]] || exit 1
    PARAM_DIR="$APP_BASE/storage/app/private/cobol/parametros_cierre/$FECHA"
    docker exec gei-app mkdir -p "$PARAM_DIR" || exit 1
    docker exec gei-app rm -f "$PARAM_DIR/.parametros_ok" || exit 1
    for f in INQTASAINT INQIVA INQREDONDE; do
      docker cp "$ORIGEN/$f" "gei-app:$PARAM_DIR/$f" || exit 1
      SHA_ORIGEN="$(sha256sum "$ORIGEN/$f" | cut -d ' ' -f 1)"
      SHA_DESTINO="$(docker exec gei-app sha256sum "$PARAM_DIR/$f" | cut -d ' ' -f 1)"
      [[ "$SHA_ORIGEN" == "$SHA_DESTINO" ]] || exit 1
      docker exec gei-app chmod 644 "$PARAM_DIR/$f" || exit 1
    done
    docker exec gei-app sh -c 'printf "%s\n" "$1" > "$2"' sh "$ACTUAL_SHA" "$PARAM_DIR/.parametros_ok" || exit 1
    docker exec gei-app chmod 644 "$PARAM_DIR/.parametros_ok" || exit 1
    docker exec gei-app chmod 755 "$PARAM_DIR" || exit 1
  ); then
    echo "ADVERTENCIA_PARAMETROS_GIMB38: no se completaron; se continua con la publicacion operativa." >&2
  else
    echo "PARAMETROS_GIMB38_PREPARADOS fecha=$FECHA"
  fi
  docker exec gei-app php artisan gei:actualizar-cobol-diario "$DESTINO_CONTENEDOR" \
    "--fecha=$FECHA" "--sha256=$ACTUAL_SHA" --confirmar
  echo "POSTGRESQL_PUBLICADO_OK fecha=$FECHA"
elif [[ "$GEI_PG_MODE" == prueba ]]; then
  for f in PROPIETAR.TXT INQUILINO.TXT CTACTEPRO.TXT INQCTACTE.TXT; do
    [[ -s "$GEI_BASE_DIR/$f" ]] || { echo "ERROR: falta historico $GEI_BASE_DIR/$f" >&2; exit 5; }
  done
  for p in comparar_cierres_cobol.py cargar_overlay_cobol.py verificar_overlay_diario_gei.py; do
    [[ -r "$GEI_TOOLS_DIR/$p" ]] || { echo "ERROR: falta $GEI_TOOLS_DIR/$p" >&2; exit 5; }
  done
  # No crear nuevas tablas/periodos historicos en produccion a ciegas.
  SQL="SELECT count(*) FROM cobol_staging.migraciones_periodos WHERE periodo='202609' AND estado='OK';"
  if ! DISPONIBLES=$(printf '%s\n' "$SQL" | docker exec -i gei-db sh -lc \
    'psql -X -A -t -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:-postgres}" -d gei_exploracion' 2>&1); then
    echo "ERROR: no se pudo consultar base historica: $DISPONIBLES" >&2
    exit 5
  fi
  [[ "$DISPONIBLES" == '1' ]] || { echo "ERROR: gei_exploracion no tiene historico 202609 OK: $DISPONIBLES" >&2; exit 5; }
  # Si hay base valida, el siguiente bloque compara 202609 y carga PRUEBA en staging.
  WORK=$(mktemp -d /tmp/gei-cierre-import.XXXXXXXX)
  python3 "$GEI_TOOLS_DIR/comparar_cierres_cobol.py" --base "$GEI_BASE_DIR" --cierre "$ORIGEN" \
    --salida "$WORK/delta" --periodo-base 202609 --fecha-cierre "$FECHA" --sede SF
  python3 "$GEI_TOOLS_DIR/cargar_overlay_cobol.py" --directorio "$WORK/delta" --aplicar-prueba
  python3 "$GEI_TOOLS_DIR/verificar_overlay_diario_gei.py" --cierre "$ORIGEN" \
    --resumen "$WORK/delta/resumen.json" --estado PRUEBA
  echo "POSTGRESQL_PRUEBA_OK fecha=$FECHA"
else
  echo 'GEI_PG_MODE=integridad: PostgreSQL sin modificaciones'
fi
rm -f -- "$EN_PROCESO"
printf 'CIERRE_PROCESADO_OK %s %s\n' "$FECHA" "$(date -Is)"
