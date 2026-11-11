#!/usr/bin/env bash
# Exportación COBOL de solo lectura de los cuatro maestros/cuentas.
# Salida fija: /home/alqui/exportados_postgresl (sin directorios por fecha).
# Primer despliegue: SOLO Fedora-clon gei-c-FHUC.
set -Eeuo pipefail
umask 027
export LC_ALL=C
export LANG=C
export TERM="${TERM:-vt100}"
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

ALQUI=/home/alqui
DEST="$ALQUI/exportados_postgresl"
HOST_ESPERADO="${GEI_EXPECTED_HOST:-gei-c-FHUC}"
if [[ "$(hostname)" != "$HOST_ESPERADO" ]]; then
  printf 'ERROR: servidor %s; esperado %s. No se ejecuta.\n' "$(hostname)" "$HOST_ESPERADO" >&2
  exit 2
fi
if [[ ! -d "$DEST" || ! -w "$DEST" ]]; then
  printf 'ERROR: %s no existe o no es escribible por %s.\n' "$DEST" "$(id -un)" >&2
  exit 2
fi
if [[ ! -d "$ALQUI/programas" ]]; then
  echo 'ERROR: falta /home/alqui/programas (ejecutables COBOL).' >&2
  exit 2
fi
RUNC="$(command -v runcobol || true)"
if [[ -z "$RUNC" ]]; then
  echo 'ERROR: runcobol no está instalado o no figura en PATH.' >&2
  exit 2
fi
CONFIG=/usr/rmcobol/config.prn
if [[ ! -f "$CONFIG" ]]; then
  echo "ERROR: falta configuración COBOL $CONFIG" >&2
  exit 2
fi
export RMC_ROOT="${RMC_ROOT:-/usr/rmcobol}"
export LD_LIBRARY_PATH="/usr/rmcobol${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
export RUNPATH="$ALQUI/programas:$ALQUI/parms${RUNPATH:+:$RUNPATH}"

# No permitir dos exportaciones concurrentes. No borra datos antiguos.
exec 9> "$DEST/.gei-export.lock"
if ! flock -n 9; then
  echo 'ERROR: ya hay otra exportación COBOL ejecutándose.' >&2
  exit 3
fi

# Solo lectura de los cuatro archivos indexados; crear TXT en el área temporal.
for f in PROPIETAR INQUILINO CTACTEPRO INQCTACTE GARANTE; do
  if [[ ! -f "$ALQUI/$f" ]]; then
    echo "ERROR: falta archivo indexado $ALQUI/$f" >&2
    exit 2
  fi
done

TMP="$(mktemp -d "$DEST/.exportando.XXXXXXXX")"
limpiar() {
  local rc=$?
  trap - EXIT
  if [[ -n "${TMP:-}" && -d "$TMP" ]]; then rm -rf -- "$TMP"; fi
  if (( rc != 0 )); then echo "EXPORTACION_FALLIDA código=$rc (no se marcó un nuevo cierre como válido)" >&2; fi
  exit "$rc"
}
trap limpiar EXIT

for f in PROPIETAR INQUILINO CTACTEPRO INQCTACTE GARANTE; do
  ln -s "$ALQUI/$f" "$TMP/$f"
done

# Estos programas leen el archivo indexado con OPEN INPUT y escriben el TXT
# en el directorio actual; secctactei/secctactep piden un Enter al terminar.
# Entregamos un Enter para permitir la ejecución sin terminal desde cron.
cd "$TMP"
for par in 'secpro:PROPIETAR.TXT:200:11' \
           'secinq:INQUILINO.TXT:560:11' \
           'secctactep:CTACTEPRO.TXT:112:27' \
           'secctactei:INQCTACTE.TXT:134:27' \
           'secgar:GARANTE.TXT:152:8'; do
  IFS=: read -r programa txt ancho clave <<< "$par"
  echo "$(date '+%F %T') | EJECUTANDO $programa"
  if ! printf '\n\n' | timeout 45m "$RUNC" "$programa" -k -c "$CONFIG" > "$TMP/$programa.runtime.log" 2>&1; then
    echo "ERROR: fallo $programa. Últimas líneas:" >&2
    tail -25 "$TMP/$programa.runtime.log" >&2 || true
    exit 4
  fi
  if [[ ! -s "$TMP/$txt" || -L "$TMP/$txt" ]]; then
    echo "ERROR: $programa no generó correctamente $txt" >&2
    exit 4
  fi
  # Comprobar cada registro: ancho fijo y clave COBOL numérica.
  if ! awk -v width="$ancho" -v keylen="$clave" '
     { n++; k=substr($0,1,keylen);
       if (length($0)!=width || length(k)!=keylen || k ~ /[^0-9]/) {
         printf "REGISTRO_INVALIDO linea=%d largo=%d esperado=%d\n", n, length($0), width > "/dev/stderr";
         bad=1; exit 1
       }
     }
     END { if (n==0) {print "ARCHIVO_VACIO" > "/dev/stderr"; bad=1}; if (bad) exit 1;
           printf "REGISTROS_OK=%d ancho=%d\n", n, width }
  ' "$TMP/$txt"; then
    echo "ERROR: ancho/clave inválidos en $txt" >&2
    exit 5
  fi
  cut -c "1-$clave" "$TMP/$txt" | sort -T "$TMP" | uniq -d > "$TMP/duplicados.tmp"
  if [[ -s "$TMP/duplicados.tmp" ]]; then
    echo "ERROR: claves duplicadas en $txt; primera: $(head -n 1 "$TMP/duplicados.tmp")" >&2
    exit 5
  fi
  echo "VALIDADO $txt bytes=$(wc -c < "$TMP/$txt")"
done

# Copias coherentes de parámetros para reproducir intereses en PostgreSQL.
PARAMETROS=(INQTASAINT INQIVA INQREDONDE INQTABCODI INQPARIDAD)
for f in "${PARAMETROS[@]}"; do
  if [[ ! -s "$ALQUI/$f" ]]; then
    echo "ERROR: falta parámetro de intereses $ALQUI/$f" >&2
    exit 5
  fi
  cp -p -- "$ALQUI/$f" "$TMP/$f"
done

SALIDAS=(PROPIETAR.TXT INQUILINO.TXT CTACTEPRO.TXT INQCTACTE.TXT GARANTE.TXT "${PARAMETROS[@]}")
sha256sum "${SALIDAS[@]}" > "$TMP/SHA256SUMS"
sha256sum --status -c "$TMP/SHA256SUMS" || {
  echo 'ERROR: verificación SHA-256 fallida en área temporal.' >&2
  exit 5
}

# Publicación. El futuro importador debe exigir EXPORTACION_OK + SHA256SUMS.
# Se retira el marcador ANTES de reemplazar archivos (evita leer un lote parcial).
rm -f -- "$DEST/EXPORTACION_OK"
for f in "${SALIDAS[@]}"; do
  mv -f -- "$TMP/$f" "$DEST/$f"
done
mv -f -- "$TMP/SHA256SUMS" "$DEST/SHA256SUMS"
cd "$DEST"
if ! sha256sum --status -c SHA256SUMS; then
  echo 'ERROR: validación SHA-256 posterior a publicación fallida.' >&2
  exit 6
fi
{
  echo "fecha=$(date '+%Y-%m-%dT%H:%M:%S%z')"
  echo "servidor=$(hostname)"
  echo "origen=/home/alqui"
  echo "archivos=${#SALIDAS[@]}"
} > "$TMP/EXPORTACION_OK"
mv -f -- "$TMP/EXPORTACION_OK" "$DEST/EXPORTACION_OK"
echo "EXPORTACION_OK | destino=$DEST | $(date '+%F %T')"
