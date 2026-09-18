from dbfread import DBF
import sqlite3
from pathlib import Path
import os
import re

DB_FACTURAS = Path("/kng/facturas.DBF")
DB_LOTES = Path("/kng/LOTES.DBF")
DIR_PDFS = Path("/kng-pdfs")

CACHE_FINAL = Path("/cache/kng_cache.sqlite")
CACHE_TMP = Path("/cache/kng_cache.sqlite.new")

RE_PDF = re.compile(
    r"^(?P<tipo>[A-Za-z]{2})-(?P<pv>\d{4})-(?P<numero>\d{8})-(?P<cuenta>\d{11})\.pdf$",
    re.IGNORECASE,
)

if CACHE_TMP.exists():
    CACHE_TMP.unlink()

con = sqlite3.connect(CACHE_TMP)
cur = con.cursor()

cur.executescript("""
PRAGMA journal_mode=OFF;
PRAGMA synchronous=OFF;
PRAGMA temp_store=MEMORY;

CREATE TABLE facturas (
    p_venta        INTEGER,
    id_factura     INTEGER,
    fecha          TEXT,
    total          REAL,
    items          INTEGER,
    gravado        REAL,
    no_gravado     REAL,
    iva            REAL,
    lote           INTEGER,
    id_inq         INTEGER,
    percepcion     REAL,
    tipo           INTEGER,
    cae            TEXT,
    vto_cae        TEXT,
    error          REAL,
    motivo         INTEGER,
    fecha_rech     TEXT,
    propieta       INTEGER,
    clean          INTEGER,
    cta_orig       INTEGER,
    alq_grav       REAL,
    alq_no_gra     REAL
);

CREATE TABLE lotes (
    id_lote        INTEGER,
    detalle        TEXT,
    comp_f         INTEGER,
    comp_nc        INTEGER,
    total_f        REAL,
    total_nc       REAL,
    desde          TEXT,
    hasta          TEXT,
    propieta       INTEGER
);

CREATE TABLE archivos_pdf (
    lote           INTEGER NOT NULL,
    tipo_archivo   TEXT NOT NULL,
    punto_venta    INTEGER NOT NULL,
    numero         INTEGER NOT NULL,
    cuenta_cobol   TEXT NOT NULL,
    archivo        TEXT NOT NULL,
    PRIMARY KEY (lote, archivo)
);
""")

print("Cargando FACTURAS.DBF...")
n_facturas = 0

for r in DBF(
    str(DB_FACTURAS),
    load=False,
    encoding="cp1252",
    char_decode_errors="ignore",
):
    cur.execute("""
        INSERT INTO facturas VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
        )
    """, (
        r["P_VENTA"],
        r["ID_FACTURA"],
        str(r["FECHA"]) if r["FECHA"] else None,
        r["TOTAL"],
        r["ITEMS"],
        r["GRAVADO"],
        r["NO_GRAVADO"],
        r["IVA"],
        r["LOTE"],
        r["ID_INQ"],
        r["PERCEPCION"],
        r["TIPO"],
        str(r["CAE"]) if r["CAE"] else None,
        str(r["VTO_CAE"]) if r["VTO_CAE"] else None,
        r["ERROR"],
        r["MOTIVO"],
        str(r["FECHA_RECH"]) if r["FECHA_RECH"] else None,
        1 if r["PROPIETA"] else 0,
        1 if r["CLEAN"] else 0,
        r["CTA_ORIG"],
        r["ALQ_GRAV"],
        r["ALQ_NO_GRA"],
    ))
    n_facturas += 1
    if n_facturas % 10000 == 0:
        print(f"{n_facturas:,} facturas...")
        con.commit()

con.commit()

print("Cargando LOTES.DBF...")
n_lotes = 0
for r in DBF(
    str(DB_LOTES),
    load=False,
    encoding="cp1252",
    char_decode_errors="ignore",
):
    cur.execute("""
        INSERT INTO lotes (
            id_lote, detalle, comp_f, comp_nc, total_f, total_nc,
            desde, hasta, propieta
        ) VALUES (?,?,?,?,?,?,?,?,?)
    """, (
        r["ID_LOTE"],
        r["DETALLE"],
        r["COMP_F"],
        r["COMP_NC"],
        r["TOTAL_F"],
        r["TOTAL_NC"],
        str(r["DESDE"]) if r["DESDE"] else None,
        str(r["HASTA"]) if r["HASTA"] else None,
        1 if r["PROPIETA"] else 0,
    ))
    n_lotes += 1

con.commit()
print(f"Lotes cargados: {n_lotes:,}")

print("Indexando PDF físicos...")
n_pdfs = 0
n_ignorados = 0

if DIR_PDFS.is_dir():
    for lote_dir in DIR_PDFS.iterdir():
        if not lote_dir.is_dir():
            continue

        m_lote = re.match(r"^lote_(\d+)$", lote_dir.name, re.IGNORECASE)
        if not m_lote:
            continue

        lote = int(m_lote.group(1))

        try:
            entradas = lote_dir.iterdir()
        except OSError:
            continue

        for p in entradas:
            if not p.is_file():
                continue

            m = RE_PDF.match(p.name)
            if not m:
                n_ignorados += 1
                continue

            cur.execute("""
                INSERT OR REPLACE INTO archivos_pdf (
                    lote, tipo_archivo, punto_venta, numero, cuenta_cobol, archivo
                ) VALUES (?, ?, ?, ?, ?, ?)
            """, (
                lote,
                m.group("tipo").upper(),
                int(m.group("pv")),
                int(m.group("numero")),
                m.group("cuenta"),
                p.name,
            ))

            n_pdfs += 1
            if n_pdfs % 10000 == 0:
                print(f"{n_pdfs:,} PDF...")
                con.commit()

con.commit()

print("Creando índices...")
cur.executescript("""
CREATE INDEX idx_facturas_id_inq
    ON facturas(id_inq);

CREATE INDEX idx_facturas_cta_orig
    ON facturas(cta_orig);

CREATE INDEX idx_facturas_lote
    ON facturas(lote);

CREATE INDEX idx_facturas_cuenta_lote
    ON facturas(id_inq, lote DESC);

CREATE INDEX idx_facturas_ctaorig_lote
    ON facturas(cta_orig, lote DESC);

CREATE INDEX idx_facturas_fecha
    ON facturas(fecha);

CREATE UNIQUE INDEX idx_lotes_id
    ON lotes(id_lote);

CREATE INDEX idx_pdf_cuenta_lote
    ON archivos_pdf(cuenta_cobol, lote DESC);

CREATE INDEX idx_pdf_lote_cuenta_pv
    ON archivos_pdf(lote, cuenta_cobol, punto_venta);

CREATE INDEX idx_pdf_numero
    ON archivos_pdf(numero);
""")

con.commit()

cant_facturas = cur.execute("SELECT COUNT(*) FROM facturas").fetchone()[0]
cant_lotes = cur.execute("SELECT COUNT(*) FROM lotes").fetchone()[0]
cant_pdfs = cur.execute("SELECT COUNT(*) FROM archivos_pdf").fetchone()[0]

if cant_facturas <= 0:
    raise RuntimeError("El cache quedó sin facturas.")
if cant_lotes <= 0:
    raise RuntimeError("El cache quedó sin lotes.")

con.close()
os.replace(CACHE_TMP, CACHE_FINAL)

print()
print("OK:", CACHE_FINAL)
print(f"Facturas cargadas: {cant_facturas:,}")
print(f"Lotes cargados: {cant_lotes:,}")
print(f"PDF indexados: {cant_pdfs:,}")
print(f"Archivos ignorados por nombre no reconocido: {n_ignorados:,}")
