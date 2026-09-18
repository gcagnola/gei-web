from pathlib import Path
import sqlite3
import sys

cuenta = int(sys.argv[1])

con = sqlite3.connect("/app/kng_cache.sqlite")
con.row_factory = sqlite3.Row

rows = con.execute("""
    SELECT
        f.*,
        l.detalle AS detalle_lote,
        l.desde,
        l.hasta
    FROM facturas f
    LEFT JOIN lotes l
           ON l.id_lote = f.lote
    WHERE f.id_inq = ?
       OR f.cta_orig = ?
    ORDER BY f.lote DESC, f.id_factura DESC
""", (cuenta, cuenta)).fetchall()

for r in rows:
    print("=" * 70)
    print("Lote:       ", r["lote"])
    print("Fecha:      ", r["fecha"])
    print("PV:         ", r["p_venta"])
    print("ID factura: ", r["id_factura"])
    print("Tipo:       ", r["tipo"])
    print("Total:      ", r["total"])
    print("CAE:        ", r["cae"])
    print("Vto CAE:    ", r["vto_cae"])
    print("Sucursal:   ", r["detalle_lote"])

    directorio = Path(f"/kng/Facturas/lote_{r['lote']}")

    if directorio.is_dir():
        pdfs = sorted(directorio.glob(f"*-{cuenta}.pdf"))
        for pdf in pdfs:
            print("PDF:        ", pdf.name)

con.close()
