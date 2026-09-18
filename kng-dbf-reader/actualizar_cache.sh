#!/bin/bash
set -euo pipefail

KNG="/home/gcagnola/dockers/samba-server/archivos/KNG"
APP="/home/gcagnola/proyectos/kng-dbf-reader"
CACHE="$APP/cache"
STATE="$APP/state"

FACTURAS="$KNG/facturas.DBF"
LOTES="$KNG/LOTES.DBF"
PDFS="$KNG/Facturas"

mkdir -p "$CACHE" "$STATE"

for f in "$FACTURAS" "$LOTES"; do
    if [ ! -r "$f" ]; then
        echo "ERROR: no se puede leer $f"
        exit 1
    fi
done

if [ ! -d "$PDFS" ]; then
    echo "ERROR: no existe $PDFS"
    exit 1
fi

SIG_ACTUAL="$(
    {
        stat -c '%n|%s|%Y' "$FACTURAS" "$LOTES"
        stat -c '%n|%Y' "$PDFS"
    } | sha256sum | awk '{print $1}'
)"

SIG_ANTERIOR=""
if [ -f "$STATE/firma.txt" ]; then
    SIG_ANTERIOR="$(cat "$STATE/firma.txt")"
fi

if [ "$SIG_ACTUAL" = "$SIG_ANTERIOR" ] &&
   [ -s "$CACHE/kng_cache.sqlite" ]; then
    exit 0
fi

echo "$(date '+%d/%m/%Y %H:%M:%S') Cambio detectado en KNG"

docker run --rm \
    --user "$(id -u):$(id -g)" \
    -v "$KNG:/kng:ro" \
    -v "$KNG/Facturas:/kng-pdfs:ro" \
    -v "$APP:/app:ro" \
    -v "$CACHE:/cache" \
    kng-dbf-reader \
    python /app/indexar_kng.py

test -s "$CACHE/kng_cache.sqlite"
echo "$SIG_ACTUAL" > "$STATE/firma.txt"

echo "$(date '+%d/%m/%Y %H:%M:%S') Cache actualizado correctamente"
