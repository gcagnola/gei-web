#!/usr/bin/env bash
# Notificaciones operativas GeI. Exclusivamente Gustavo y sucursal Santa Fe.
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

ACCION="${1:-}"
COMPONENTE="${2:-}"
DETALLE="${3:-Sin detalle}"
case "$ACCION" in ERROR|RECUPERADO|PRUEBA) ;; *) echo 'Uso: gei-alerta-email.sh ERROR|RECUPERADO|PRUEBA componente detalle' >&2; exit 2;; esac
[[ "$COMPONENTE" =~ ^[a-z0-9_-]{1,50}$ ]] || { echo 'Componente invalido' >&2; exit 2; }
[[ "$(id -u)" == 0 ]] || { echo 'Requiere root' >&2; exit 2; }

FROM='sistemas@geiycia.com.ar'
TO='gustavo.cagnola@gmail.com, guastavinoeimbertycia@gmail.com'
SENDMAIL='/usr/sbin/sendmail'
ESTADOS='/var/lib/gei-alertas'
[[ -x "$SENDMAIL" ]] || { echo "ERROR: no existe transporte de correo $SENDMAIL" >&2; exit 10; }
mkdir -p "$ESTADOS"
chmod 700 "$ESTADOS"
exec 9>"$ESTADOS/.lock"
flock -x 9
ARCHIVO="$ESTADOS/$COMPONENTE.estado"
ANTERIOR=''
[[ ! -f "$ARCHIVO" ]] || ANTERIOR="$(head -n 1 "$ARCHIVO")"
HOY="$(date +%F)"

if [[ "$ACCION" == ERROR && "$ANTERIOR" == "ERROR|$HOY" ]]; then
    echo "ALERTA_OMITIDA: error ya notificado hoy ($COMPONENTE)"
    exit 0
fi
if [[ "$ACCION" == RECUPERADO && "$ANTERIOR" != ERROR\|* ]]; then
    # Sin falla previa notificada, no generar mensajes de exito cada noche.
    echo "SIN_ALERTA_RECUPERACION: no habia error pendiente ($COMPONENTE)"
    exit 0
fi

DETALLE="${DETALLE//$'\r'/ }"
DETALLE="${DETALLE//$'\n'/ }"
DETALLE="${DETALLE:0:1800}"
SERVIDOR="$(hostname -s)"
ASUNTO="[GeI] $ACCION - $COMPONENTE - $SERVIDOR"

MENSAJE="$(printf 'From: %s\nTo: %s\nSubject: %s\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\n\nServidor: %s\nComponente: %s\nEstado: %s\nFecha: %s\nDetalle: %s\n\nAviso automatico GeI. Verificar registros del servidor.\n' \
    "$FROM" "$TO" "$ASUNTO" "$SERVIDOR" "$COMPONENTE" "$ACCION" "$(date -Is)" "$DETALLE")"

if [[ "${GEI_ALERTA_SIMULAR:-0}" == 1 ]]; then
    printf '%s\n' "$MENSAJE"
    echo 'SIMULACION: correo no enviado; estado sin cambios'
    exit 0
fi

# sendmail confirma aceptacion por el transporte local, no entrega al destinatario.
if ! printf '%s\n' "$MENSAJE" | "$SENDMAIL" -t -oi -f "$FROM"; then
    echo "ERROR: sendmail no acepto el aviso ($COMPONENTE / $ACCION)" >&2
    exit 11
fi

if [[ "$ACCION" == ERROR || "$ACCION" == RECUPERADO ]]; then
    tmp="$(mktemp "$ESTADOS/.estado.XXXXXXXX")"
    if [[ "$ACCION" == ERROR ]]; then
        printf 'ERROR|%s\n' "$HOY" > "$tmp"
    else
        printf 'OK|%s\n' "$HOY" > "$tmp"
    fi
    chmod 600 "$tmp"
    mv -f -- "$tmp" "$ARCHIVO"
fi
printf 'CORREO_ACEPTADO: %s %s\n' "$ACCION" "$COMPONENTE"
