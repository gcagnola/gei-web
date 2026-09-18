# KNG DBF Reader + Cache SQLite para GeI-Web

## Objetivo

Este componente permite consultar la facturación histórica de KNG sin modificar los archivos Visual FoxPro originales.

Arquitectura:

```text
KNG (solo lectura)
  ├─ facturas.DBF
  ├─ LOTES.DBF
  └─ Facturas/lote_*/
          ↓
Docker Python + dbfread
          ↓
kng_cache.sqlite.new
          ↓ rename atómico
kng_cache.sqlite
          ↓
GeI-Web
```

Laravel no debe leer directamente los DBF. Debe consultar un SQLite regenerable e indexado.

---

## 1. Estructura en test (`debian13`)

```bash
mkdir -p ~/proyectos/kng-dbf-reader/{cache,state}
cd ~/proyectos/kng-dbf-reader
```

Estructura esperada:

```text
~/proyectos/kng-dbf-reader/
├── Dockerfile
├── indexar_kng.py
├── actualizar_cache.sh
├── cache/
│   └── kng_cache.sqlite
└── state/
    └── firma.txt
```

---

## 2. Dockerfile

```bash
cat > Dockerfile <<'EOF'
FROM python:3.12-slim

RUN pip install --no-cache-dir dbfread

WORKDIR /app
EOF
```

Construir:

```bash
docker build -t kng-dbf-reader .
```

---

## 3. Fuentes KNG

```text
/home/gcagnola/dockers/samba-server/archivos/KNG/facturas.DBF
/home/gcagnola/dockers/samba-server/archivos/KNG/LOTES.DBF
/home/gcagnola/dockers/samba-server/archivos/KNG/Facturas/lote_*
```

Siempre deben montarse en modo `ro`.

---

## 4. Campos principales de FACTURAS.DBF

```text
P_VENTA      → punto de venta
ID_FACTURA   → identificador interno
FECHA        → fecha del comprobante
TOTAL        → total
LOTE         → número de lote
ID_INQ       → cuenta COBOL
TIPO         → tipo de comprobante
CAE          → CAE
VTO_CAE      → vencimiento CAE
PROPIETA     → indicador propietario
CTA_ORIG     → cuenta original
```

Otros campos disponibles:

```text
ITEMS
GRAVADO
NO_GRAVADO
IVA
PERCEPCION
ERROR
MOTIVO
FECHA_RECH
CLEAN
ALQ_GRAV
ALQ_NO_GRA
```

---

## 5. Campos principales de LOTES.DBF

```text
ID_LOTE
DETALLE
COMP_F
COMP_NC
TOTAL_F
TOTAL_NC
GRAV_F
GRAV_NC
NO_GRAV_F
NO_GRAV_NC
IVA_F
IVA_NC
PERC_F
PERC_NC
DESDE
HASTA
PROPIETA
```

Relación principal:

```text
FACTURAS.DBF.LOTE → LOTES.DBF.ID_LOTE
```

Los PDF físicos están en:

```text
Facturas/lote_<ID_LOTE>/
```

---

## 6. Reglas del indexador

`indexar_kng.py` debe generar primero un archivo temporal:

```python
CACHE_FINAL = "/cache/kng_cache.sqlite"
CACHE_TMP = "/cache/kng_cache.sqlite.new"
```

Al comenzar:

```python
from pathlib import Path
import os

tmp = Path(CACHE_TMP)

if tmp.exists():
    tmp.unlink()
```

Abrir SQLite sobre el temporal:

```python
con = sqlite3.connect(CACHE_TMP)
```

Al finalizar correctamente:

```python
con.commit()
con.close()

os.replace(CACHE_TMP, CACHE_FINAL)

print("Cache actualizado:", CACHE_FINAL)
```

Esto garantiza reemplazo atómico: GeI-Web usa la versión anterior hasta que la nueva termina de construirse.

Índices recomendados:

```sql
CREATE INDEX idx_facturas_id_inq
    ON facturas(id_inq);

CREATE INDEX idx_facturas_lote
    ON facturas(lote);

CREATE INDEX idx_facturas_cuenta_lote
    ON facturas(id_inq, lote DESC);

CREATE INDEX idx_facturas_fecha
    ON facturas(fecha);

CREATE UNIQUE INDEX idx_lotes_id
    ON lotes(id_lote);
```

---

## 7. Script `actualizar_cache.sh`

```bash
cat > ~/proyectos/kng-dbf-reader/actualizar_cache.sh <<'EOF'
#!/bin/bash
set -euo pipefail

KNG="/home/gcagnola/dockers/samba-server/archivos/KNG"
APP="/home/gcagnola/proyectos/kng-dbf-reader"
CACHE="$APP/cache"
STATE="$APP/state"

FACTURAS="$KNG/facturas.DBF"
LOTES="$KNG/LOTES.DBF"

mkdir -p "$CACHE" "$STATE"

if [ ! -r "$FACTURAS" ]; then
    echo "ERROR: no se puede leer $FACTURAS"
    exit 1
fi

if [ ! -r "$LOTES" ]; then
    echo "ERROR: no se puede leer $LOTES"
    exit 1
fi

SIG_ACTUAL="$(
    stat -c '%n|%s|%Y' "$FACTURAS" "$LOTES" |
    sha256sum |
    awk '{print $1}'
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

docker run --rm     -v "$KNG:/kng:ro"     -v "$APP:/app:ro"     -v "$CACHE:/cache"     kng-dbf-reader     python /app/indexar_kng.py

test -s "$CACHE/kng_cache.sqlite"

echo "$SIG_ACTUAL" > "$STATE/firma.txt"

echo "$(date '+%d/%m/%Y %H:%M:%S') Cache actualizado correctamente"
EOF

chmod +x ~/proyectos/kng-dbf-reader/actualizar_cache.sh
```

La parte crítica es:

```text
$KNG:/kng:ro
```

Los archivos KNG quedan siempre en solo lectura.

---

## 8. Primera ejecución manual

```bash
~/proyectos/kng-dbf-reader/actualizar_cache.sh
```

Verificar:

```bash
ls -lh ~/proyectos/kng-dbf-reader/cache/kng_cache.sqlite
file ~/proyectos/kng-dbf-reader/cache/kng_cache.sqlite
```

---

## 9. Verificar cantidades

```bash
docker run --rm   -v ~/proyectos/kng-dbf-reader/cache:/cache:ro   python:3.12-slim   python - <<'PY'
import sqlite3

db = sqlite3.connect("/cache/kng_cache.sqlite")

for tabla in ["facturas", "lotes"]:
    n = db.execute(f"select count(*) from {tabla}").fetchone()[0]
    print(tabla, n)

db.close()
PY
```

---

## 10. Automatización con systemd

### `/etc/systemd/system/kng-cache.service`

```ini
[Unit]
Description=Actualizar cache SQLite de facturacion KNG
After=docker.service network-online.target
Requires=docker.service

[Service]
Type=oneshot
User=gcagnola
Group=gcagnola
ExecStart=/home/gcagnola/proyectos/kng-dbf-reader/actualizar_cache.sh
```

### `/etc/systemd/system/kng-cache.timer`

```ini
[Unit]
Description=Verificar cambios en FACTURAS.DBF y LOTES.DBF

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Persistent=true

[Install]
WantedBy=timers.target
```

Activar:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now kng-cache.timer
```

Verificar:

```bash
systemctl status kng-cache.timer
systemctl list-timers | grep kng
```

---

## 11. Logs

Ejecutar manualmente:

```bash
sudo systemctl start kng-cache.service
```

Ver últimos logs:

```bash
journalctl -u kng-cache.service -n 50 --no-pager
```

Seguir en vivo:

```bash
journalctl -u kng-cache.service -f
```

Cuando no hay cambios, el servicio termina rápidamente.

Cuando cambia `facturas.DBF` o `LOTES.DBF`:

```text
Cambio detectado en KNG
...
Cache actualizado correctamente
```

---

## 12. Integración con GeI-Web

En test, el cache queda en:

```text
/home/gcagnola/proyectos/kng-dbf-reader/cache/kng_cache.sqlite
```

Montarlo en `gei-app` como solo lectura:

```yaml
volumes:
  - /home/gcagnola/proyectos/kng-dbf-reader/cache/kng_cache.sqlite:/kng-cache/kng_cache.sqlite:ro
```

Dentro del contenedor Laravel:

```text
/kng-cache/kng_cache.sqlite
```

Agregar al `.env`:

```env
KNG_CACHE_DB=/kng-cache/kng_cache.sqlite
```

Laravel debe consultar el SQLite y nunca los DBF directamente.

---

# Reproducción en producción (`debian-gei`)

Acceso:

```bash
ssh -p 2222 gcagnola@181.28.3.188
```

## 13. Crear estructura

```bash
mkdir -p ~/proyectos/kng-dbf-reader/{cache,state}
cd ~/proyectos/kng-dbf-reader
```

---

## 14. Copiar desde test

Desde `debian13`:

```bash
scp -P 2222   ~/proyectos/kng-dbf-reader/Dockerfile   ~/proyectos/kng-dbf-reader/indexar_kng.py   ~/proyectos/kng-dbf-reader/actualizar_cache.sh   gcagnola@181.28.3.188:/home/gcagnola/proyectos/kng-dbf-reader/
```

---

## 15. Construir imagen en producción

```bash
cd ~/proyectos/kng-dbf-reader
docker build -t kng-dbf-reader .
```

---

## 16. Verificar fuentes

```bash
ls -lh ~/dockers/samba-server/archivos/KNG/facturas.DBF
ls -lh ~/dockers/samba-server/archivos/KNG/LOTES.DBF
ls -ld ~/dockers/samba-server/archivos/KNG/Facturas
```

Si las rutas son iguales a test, no hace falta modificar `actualizar_cache.sh`.

---

## 17. Primera reconstrucción en producción

```bash
cd ~/proyectos/kng-dbf-reader
./actualizar_cache.sh
```

Verificar:

```bash
ls -lh cache/kng_cache.sqlite
```

---

## 18. Instalar systemd en producción

Crear los mismos archivos:

```text
/etc/systemd/system/kng-cache.service
/etc/systemd/system/kng-cache.timer
```

Luego:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now kng-cache.timer
```

Verificar:

```bash
systemctl status kng-cache.timer
journalctl -u kng-cache.service -n 50 --no-pager
```

---

## 19. Regla operativa

En test y producción:

```text
FACTURAS.DBF     → solo lectura
LOTES.DBF        → solo lectura
Facturas/        → solo lectura

kng_cache.sqlite → regenerable
```

Nunca ejecutar sobre los DBF originales:

```text
PACK
REINDEX
ZAP
DELETE
RECALL
REPLACE
USE ... EXCLUSIVE
```

---

## 20. Reconstrucción manual completa

Si el cache debe regenerarse desde cero:

```bash
rm -f ~/proyectos/kng-dbf-reader/cache/kng_cache.sqlite
rm -f ~/proyectos/kng-dbf-reader/cache/kng_cache.sqlite.new
rm -f ~/proyectos/kng-dbf-reader/state/firma.txt

~/proyectos/kng-dbf-reader/actualizar_cache.sh
```

Esto no modifica KNG.

---

## 21. Uso previsto en GeI-Web

La resolución será:

```text
gei_core.personas_cuentas_cobol
        ↓
cuenta COBOL
        ↓
kng_cache.sqlite.facturas
        ↓
LOTE
        ↓
Facturas/lote_N/
        ↓
PDF físico
```

Visualización recomendada:

```text
Lote 1245    FB-0038-00281234
Lote 1244    FB-0038-00279...
Lote 1236    FB-0038-00274426
```

Orden:

```sql
ORDER BY lote DESC, id_factura DESC
```

Una vez cerrada esta integración, el siguiente bloque funcional es:

```text
Liquidaciones de propietarios
Impuestos garantizados
```
