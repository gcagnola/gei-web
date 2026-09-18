# KNG cache v3 - índice de PDFs

Este cambio agrega `archivos_pdf` al SQLite.

Motivo: GeI-Web ya no debe ejecutar `glob()` sobre directorios `lote_*`
cada vez que abre un cliente. Ese recorrido era la principal causa de demora.

## Aplicar

```bash
cd ~/proyectos/kng-dbf-reader
tar -xzf ~/Descargas/kng-dbf-reader-v3-pdfs.tar.gz
chmod +x actualizar_cache.sh

rm -f state/firma.txt
rm -f cache/kng_cache.sqlite.new
./actualizar_cache.sh
```

No borres previamente `cache/kng_cache.sqlite`; el reemplazo es atómico.

## Verificar

```bash
python - <<'PY'
import sqlite3
db=sqlite3.connect("cache/kng_cache.sqlite")
for t in ["facturas","lotes","archivos_pdf"]:
    print(t, db.execute(f"select count(*) from {t}").fetchone()[0])
PY
```
