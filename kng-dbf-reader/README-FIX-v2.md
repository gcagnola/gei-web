# Fix KNG DBF Reader v2

Corrige dos problemas:

1. `indexar_kng.py` ahora escribe en `/cache/kng_cache.sqlite`, que es el volumen persistente.
2. El cache se genera como `.new` y sólo se reemplaza al final si contiene facturas y lotes.
3. Se cuenta y valida `LOTES.DBF`.
4. `docker run` usa el UID/GID del usuario para evitar archivos `root:root`.

## Aplicar

```bash
cd ~/proyectos/kng-dbf-reader
tar -xzf ~/Descargas/kng-dbf-reader-fix-v2.tar.gz
chmod +x actualizar_cache.sh

rm -f state/firma.txt
rm -f cache/kng_cache.sqlite.new

./actualizar_cache.sh
```

No es necesario borrar previamente `cache/kng_cache.sqlite`; el reemplazo es atómico.

## Verificar

```bash
python - <<'PY'
import sqlite3
db=sqlite3.connect("cache/kng_cache.sqlite")
print("Facturas:", db.execute("select count(*) from facturas").fetchone()[0])
print("Lotes:", db.execute("select count(*) from lotes").fetchone()[0])
PY
```

Esperado actualmente: 330000 facturas y 1196 lotes aproximadamente.
