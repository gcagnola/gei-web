#!/usr/bin/env bash
# Ejecutar UNICAMENTE despues de un respaldo /home exitoso en gei-c-central.
# Exporta indexados COBOL en Fedora y envia SOLO los archivos exportados al respaldo en Debian.
set -Eeuo pipefail
umask 027
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

[[ "$(hostname)" == 'gei-c-central' ]] || { echo 'ERROR: solo gei-c-central' >&2; exit 2; }
[[ "$(id -u)" == 0 ]] || { echo 'ERROR: ejecutar como root (desde backup)' >&2; exit 2; }

# Mismo transporte que el backup existente, que envia /home a '.' del usuario remoto.
REMOTE='tunel-fedora8@192.168.10.50'
PORT=22008
KEY=/root/.ssh/id_rsa_backup_debian
SRC=/home/alqui/exportados_postgresl
DST='./alqui/exportados_postgresl/'

[[ -r "$KEY" ]] || { echo "ERROR: falta clave SSH $KEY" >&2; exit 2; }
[[ -x /home/alqui/bin/gei-exportar-postgresl.sh ]] || { echo 'ERROR: exportador no instalado' >&2; exit 2; }

# El propio exportador tiene flock; aqui evitamos superposiciones de toda esta segunda etapa.
exec 8>/var/lock/gei-post-backup.lock
flock -n 8 || { echo 'ERROR: posbackup COBOL en ejecucion' >&2; exit 3; }

printf 'INICIO_POST_BACKUP=%s\n' "$(date -Is)"
echo '===== EXPORTACION COBOL ====='
su -s /bin/bash alqui -c 'GEI_EXPECTED_HOST=gei-c-central /home/alqui/bin/gei-exportar-postgresl.sh'

cd "$SRC"
[[ -s SHA256SUMS && -s EXPORTACION_OK ]] || { echo 'ERROR: falta manifiesto de exportacion' >&2; exit 4; }
sha256sum -c SHA256SUMS
grep -qx 'servidor=gei-c-central' EXPORTACION_OK || { echo 'ERROR: exportacion de otro servidor' >&2; exit 4; }
grep -qx 'archivos=10' EXPORTACION_OK || { echo 'ERROR: cantidad de archivos diferente de 10' >&2; exit 4; }
FECHA_EXPORT=$(sed -n 's/^fecha=\([0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\}\)T.*/\1/p' EXPORTACION_OK)
[[ "$FECHA_EXPORT" == "$(date +%F)" ]] || { echo "ERROR: exportacion no es de hoy: $FECHA_EXPORT" >&2; exit 4; }

SSH='ssh -p 22008 -i /root/.ssh/id_rsa_backup_debian -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15'
# rsync solo: no se supone que el usuario remoto pueda ejecutar comandos interactivos.
# La ruta relativa al HOME remoto coincide con el '.' utilizado por el backup funcional.
echo '===== TRANSFERENCIA DE ARCHIVOS Y PARAMETROS ====='
rsync -rt --delay-updates -e "$SSH" \
  PROPIETAR.TXT INQUILINO.TXT CTACTEPRO.TXT INQCTACTE.TXT GARANTE.TXT \
  INQTASAINT INQIVA INQREDONDE INQTABCODI INQPARIDAD SHA256SUMS \
  "$REMOTE:$DST"
# Ultima transferencia: marcador del lote completo.
rsync -rt --delay-updates -e "$SSH" EXPORTACION_OK "$REMOTE:$DST"

# Señal final para Debian. La unidad systemd.path la detectara y arrancara la importacion.
# Se genera solo despues de las transferencias anteriores exitosas.
SENIAL=$(mktemp /tmp/gei-cierre-pendiente.XXXXXXXX)
trap 'rm -f "$SENIAL"' EXIT
printf 'fecha=%s\nsha256=%s\n' "$FECHA_EXPORT" "$(sha256sum EXPORTACION_OK | awk '{print $1}')" > "$SENIAL"
rsync -rt -e "$SSH" "$SENIAL" "$REMOTE:${DST}.gei-importar-pendiente"
echo "TRANSFERENCIA_COMPLETA_OK | fecha=$FECHA_EXPORT | $(date -Is)"
