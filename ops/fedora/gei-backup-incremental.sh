#!/bin/bash

# Backup incremental Fedora Santa Fe -> gei-produccion
# Origen: /home/
# Destino: /srv/backups-fedora/santa-fe/home/
# Receptor: rsync con fake-super

LOGDIR="/var/log/gei-backup"
LOCK="/var/lock/gei-backup-santafe.lock"
FECHA=$(date +%Y%m%d-%H%M%S)
LOG="$LOGDIR/santafe-$FECHA.log"
GEI_CONTROL_DIR=''
finalizar_gei() {
    local rc=$?
    local motivo=''
    trap - EXIT
    if [[ -n "$GEI_CONTROL_DIR" && -d "$GEI_CONTROL_DIR" ]]; then
        rm -rf -- "$GEI_CONTROL_DIR"
    fi
    if (( rc != 0 )); then
        if [[ -r "$LOG" ]]; then
            motivo="$(grep -E '^ERROR:|^ERROR EN BACKUP|^ADVERTENCIA_' "$LOG" \
                | tail -n 3 | tr '\n' ';' || true)"
        fi
        /usr/local/sbin/gei-alerta-email.sh ERROR respaldo-fedora \
            "Codigo $rc. ${motivo:-Consultar registro} Log: $LOG" >&2 || \
            echo 'ERROR: no se pudo enviar alerta del respaldo' >&2
    else
        /usr/local/sbin/gei-alerta-email.sh RECUPERADO respaldo-fedora \
            "Respaldo y transferencia finalizados; log: $LOG" >&2 || true
    fi
    exit "$rc"
}
trap finalizar_gei EXIT

mkdir -p "$LOGDIR"
chmod 700 "$LOGDIR"

exec 9>"$LOCK"

if ! flock -n 9; then
    echo "ERROR: otro respaldo esta ejecutandose"
    exit 1
fi

{
    echo "===== BACKUP FEDORA SANTA FE ====="
    echo "Inicio: $(date)"

    # Si quedaron terminales COBOL abiertas, exigir ausencia comprobable de
    # actividad en procesos y archivos indexados. No matar sesiones.
    GEI_CONTROL_DIR="$(mktemp -d /tmp/gei-cobol-quiet.XXXXXXXX)" || exit 2
    if ! /usr/local/sbin/gei-cobol-quiescencia.sh inicio "$GEI_CONTROL_DIR"; then
        echo 'ERROR: actividad COBOL detectada; respaldo omitido'
        exit 2
    fi

    rsync -aHAX \
        --numeric-ids \
        --one-file-system \
        --delete \
        --itemize-changes \
        --stats \
        --exclude='/windows/.gvfs/***' \
        -e 'ssh -p 22008 -i /root/.ssh/id_rsa_backup_debian -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15' \
        /home/ \
        tunel-fedora8@192.168.10.50:.

    RESULTADO=$?

    echo
    echo "Fin: $(date)"
    echo "Codigo: $RESULTADO"

    if [ "$RESULTADO" -eq 0 ]; then
        if ! /usr/local/sbin/gei-cobol-quiescencia.sh fin "$GEI_CONTROL_DIR"; then
            echo 'ERROR: cambios COBOL durante la copia; respaldo NO certificado, no exportar'
            exit 21
        fi
        echo "BACKUP CORRECTO"
        if ! /usr/local/sbin/gei-post-backup-fedora.sh; then
            echo "ERROR: exportacion/transferencia tras backup"
            exit 12
        fi
    else
        echo "ERROR EN BACKUP"
    fi

    exit "$RESULTADO"

} > "$LOG" 2>&1
