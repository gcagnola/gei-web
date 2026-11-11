#!/usr/bin/env bash
# Comprobacion conservadora: solo habilita respaldo con actividad COBOL no detectable.
# No bloquea escrituras; si se producen durante rsync, el respaldo NO se certifica.
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

MODO="${1:-}"
ESTADO="${2:-}"
[[ "$(id -u)" == 0 ]] || { echo 'ERROR: debe ejecutarse como root' >&2; exit 2; }
[[ "$MODO" == inicio || "$MODO" == fin ]] || { echo 'Uso: gei-cobol-quiescencia.sh inicio|fin /tmp/estado' >&2; exit 2; }
[[ -n "$ESTADO" && -d "$ESTADO" && ! -L "$ESTADO" ]] || { echo 'ERROR: falta directorio de control' >&2; exit 2; }
[[ -d /home/alqui ]] || { echo 'ERROR: falta /home/alqui' >&2; exit 2; }

# No autorizar la excepcion durante el horario de atencion.
HHMM="$(date +%H%M)"
if (( 10#$HHMM >= 800 && 10#$HHMM < 1730 )); then
    echo "ERROR: horario de atencion ($HHMM); no se habilita respaldo con COBOL abierto" >&2
    exit 3
fi

capturar() {
    local destino="$1" pids pid stat_ini linea_io archivo
    mkdir -p "$destino"
    # Todas las entradas regulares de primer nivel: aqui viven los indexados COBOL.
    # Capturamos mtime/ctime con nanosegundos, tamaño y nombre; no leemos contenido.
    find /home/alqui -maxdepth 1 \( -type f -o -type l \) -print0 \
      | LC_ALL=C sort -z \
      | while IFS= read -r -d '' archivo; do
            stat -c '%n|%s|%y|%z|%F' -- "$archivo" || exit 9
        done > "$destino/datos"
    pids="$(pgrep -x runcobol || true)"
    : > "$destino/procesos"
    for pid in $pids; do
        [[ -r "/proc/$pid/stat" && -r "/proc/$pid/io" ]] || {
            echo "ERROR: proceso $pid cambio mientras se verificaba" >&2
            return 9
        }
        stat_ini="$(awk '{print $22}' "/proc/$pid/stat")"
        linea_io="$(awk '/^(syscr|syscw|wchar|write_bytes|cancelled_write_bytes):/ {printf "%s=%s;", $1, $2}' "/proc/$pid/io")"
        printf '%s|%s|%s\n' "$pid" "$stat_ini" "$linea_io" >> "$destino/procesos"
    done
    LC_ALL=C sort -o "$destino/procesos" "$destino/procesos"
}

if [[ "$MODO" == inicio ]]; then
    capturar "$ESTADO/a"
    echo 'QUIESCENCIA: comprobando estabilidad durante 20 segundos...'
    sleep 20
    capturar "$ESTADO/b"
    if ! cmp -s "$ESTADO/a/datos" "$ESTADO/b/datos" || ! cmp -s "$ESTADO/a/procesos" "$ESTADO/b/procesos"; then
        echo 'ERROR: se detectaron cambios en datos o actividad COBOL durante el control previo' >&2
        exit 4
    fi
    echo "QUIESCENCIA_PREVIA_OK: $(wc -l < "$ESTADO/b/procesos") procesos runcobol estables"
else
    [[ -f "$ESTADO/b/datos" && -f "$ESTADO/b/procesos" ]] || { echo 'ERROR: falta control inicial' >&2; exit 5; }
    capturar "$ESTADO/c"
    if ! cmp -s "$ESTADO/b/datos" "$ESTADO/c/datos" || ! cmp -s "$ESTADO/b/procesos" "$ESTADO/c/procesos"; then
        echo 'ERROR: datos o procesos COBOL cambiaron durante rsync. Copia no validada.' >&2
        exit 6
    fi
    echo 'QUIESCENCIA_POSTERIOR_OK: sin cambios detectables durante rsync'
fi
