#!/usr/bin/env bash
# Alertas del importador y control matutino: no modifica PostgreSQL.
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

[[ "$(hostname -s)" == gei-produccion && "$(id -u)" == 0 ]] || { echo 'ERROR: ejecutar como root en gei-produccion' >&2; exit 2; }
MODO="${1:-}"
ALERTA='/usr/local/sbin/gei-alerta-email.sh'

ultima_publicacion() {
    printf '%s\n' "SELECT COALESCE(to_char(MAX(fecha_cierre), 'YYYY-MM-DD'), '') FROM cobol_publicaciones WHERE estado='PUBLICADO';" \
      | docker exec -i gei-db sh -ec 'exec psql -X -A -t -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d gei_db'
}

case "$MODO" in
    --fallo-importacion)
        MOTIVO="$(journalctl -u gei-importar-cierre.service -n 60 --no-pager -o cat 2>/dev/null \
          | grep -E 'ERROR:|ERROR_IMPORTACION|ADVERTENCIA_PARAMETROS_GIMB38' \
          | tail -n 3 | tr '\n' ' ' || true)"
        [[ -n "$MOTIVO" ]] || MOTIVO='Servicio systemd de importacion finalizo con fallo; revisar journalctl.'
        "$ALERTA" ERROR importacion-cobol "$MOTIVO"
        ;;
    --importacion-ok)
        # ExecStartPost puede ejecutarse aun cuando no habia lote pendiente.
        # No notificar recuperacion si no existe publicacion valida de hoy.
        if ULTIMA="$(ultima_publicacion 2>/dev/null)" && [[ "$ULTIMA" == "$(date +%F)" ]]; then
            "$ALERTA" RECUPERADO importacion-cobol "Cierre $ULTIMA publicado correctamente"
            "$ALERTA" RECUPERADO cierre-nocturno "Publicacion operativa al dia: $ULTIMA"
        else
            echo 'SIN_RECUPERACION: no existe cierre publicado de hoy'
        fi
        ;;
    --matutino)
        AYER="$(date -d yesterday +%F)"
        if ! ULTIMA="$(ultima_publicacion 2>&1)"; then
            "$ALERTA" ERROR cierre-nocturno "No se pudo consultar PostgreSQL; detalle: ${ULTIMA:0:500}"
            exit 1
        fi
        ULTIMA="$(printf '%s' "$ULTIMA" | tr -d '\r\n[:space:]')"
        if [[ ! "$ULTIMA" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
            "$ALERTA" ERROR cierre-nocturno "Consulta devolvio fecha invalida o vacia: ${ULTIMA:0:100}"
            exit 1
        fi
        if [[ "$ULTIMA" < "$AYER" ]]; then
            "$ALERTA" ERROR cierre-nocturno "A las 08:15 se esperaba cierre >= $AYER; ultimo PUBLICADO = $ULTIMA"
            exit 1
        fi
        "$ALERTA" RECUPERADO cierre-nocturno "Ultimo cierre publicado: $ULTIMA"
        echo "PUBLICACION_AL_DIA ultimo=$ULTIMA exigido_desde=$AYER"
        ;;
    *) echo 'Uso: gei-vigilar-publicacion.sh --fallo-importacion|--importacion-ok|--matutino' >&2; exit 2;;
esac
