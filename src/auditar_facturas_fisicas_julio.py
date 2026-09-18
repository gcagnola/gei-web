#!/usr/bin/env python3
import csv, os, re
from collections import Counter, defaultdict

FACTURAS_CSV = "/var/www/html/storage/app/private/facturas_kng_julio_2026.csv"
ROOT_PDFS = "/archivo-kng/facturas/2026/07"
SALIDA = "/var/www/html/storage/app/private/facturas_fisicas_julio_2026_por_lote.csv"

TIPO_A_PREFIJO = {1: "FA", 3: "NCA", 6: "FB", 8: "NCB"}
rx = re.compile(r"^(?P<tipo>[A-Za-z]+)-(?P<pv>\d+)-(?P<numero>\d+)-(?P<cuenta>\d+)\.pdf$", re.I)

facturas = {}
por_numero = defaultdict(list)

with open(FACTURAS_CSV, newline="", encoding="utf-8") as fh:
    for r in csv.DictReader(fh):
        pv = int(r["P_VENTA"])
        numero = int(r["ID_FACTURA"])
        tipo_num = int(r["TIPO"])
        tipo = TIPO_A_PREFIJO.get(tipo_num, f"T{tipo_num}")
        clave = (tipo, pv, numero)
        f = {
            "lote": int(r["LOTE"]),
            "pv": pv,
            "tipo_num": tipo_num,
            "tipo": tipo,
            "numero": numero,
            "cuenta_destino": (r.get("ID_INQ") or "").strip(),
            "cuenta_origen": (r.get("CTA_ORIG") or "").strip(),
            "fecha": (r.get("FECHA") or "").strip(),
            "total": (r.get("TOTAL") or "").strip(),
            "cae": (r.get("CAE") or "").strip(),
        }
        facturas[clave] = f
        por_numero[(pv, numero)].append(f)

filas = []
for base, _, archivos in os.walk(ROOT_PDFS):
    for nombre in archivos:
        if not nombre.lower().endswith(".pdf"):
            continue
        ruta = os.path.join(base, nombre)
        rel = os.path.relpath(ruta, "/archivo-kng/facturas")
        m = rx.match(nombre)

        if not m:
            filas.append({
                "estado":"NOMBRE_NO_INTERPRETABLE","lote":"","pv":"","tipo_num":"","tipo":"",
                "numero":"","cuenta_pdf":"","cuenta_destino":"","cuenta_origen":"",
                "fecha":"","total":"","cae":"","archivo":nombre,"ruta_relativa":rel
            })
            continue

        tipo = m.group("tipo").upper()
        pv = int(m.group("pv"))
        numero = int(m.group("numero"))
        cuenta_pdf = m.group("cuenta")
        clave = (tipo, pv, numero)
        f = facturas.get(clave)

        if f:
            cuentas_validas = {f["cuenta_destino"]}
            if f["cuenta_origen"]:
                cuentas_validas.add(f["cuenta_origen"])
            estado = "OK" if cuenta_pdf in cuentas_validas else "CUENTA_DISTINTA"
            filas.append({
                "estado":estado,"lote":f["lote"],"pv":f["pv"],"tipo_num":f["tipo_num"],"tipo":f["tipo"],
                "numero":f["numero"],"cuenta_pdf":cuenta_pdf,"cuenta_destino":f["cuenta_destino"],
                "cuenta_origen":f["cuenta_origen"],"fecha":f["fecha"],"total":f["total"],"cae":f["cae"],
                "archivo":nombre,"ruta_relativa":rel
            })
        else:
            estado = "TIPO_DISTINTO" if por_numero.get((pv, numero)) else "NO_ES_FACTURA_JULIO"
            filas.append({
                "estado":estado,"lote":"","pv":pv,"tipo_num":"","tipo":tipo,"numero":numero,
                "cuenta_pdf":cuenta_pdf,"cuenta_destino":"","cuenta_origen":"","fecha":"","total":"",
                "cae":"","archivo":nombre,"ruta_relativa":rel
            })

claves_pdf = {
    (r["tipo"], int(r["pv"]), int(r["numero"]))
    for r in filas
    if r["estado"] in {"OK","CUENTA_DISTINTA"} and r["pv"] != ""
}

for clave, f in facturas.items():
    if clave not in claves_pdf:
        filas.append({
            "estado":"PDF_FALTANTE","lote":f["lote"],"pv":f["pv"],"tipo_num":f["tipo_num"],"tipo":f["tipo"],
            "numero":f["numero"],"cuenta_pdf":"","cuenta_destino":f["cuenta_destino"],
            "cuenta_origen":f["cuenta_origen"],"fecha":f["fecha"],"total":f["total"],"cae":f["cae"],
            "archivo":"","ruta_relativa":""
        })

filas.sort(key=lambda r: (
    999999 if r["lote"] == "" else int(r["lote"]),
    999 if r["pv"] == "" else int(r["pv"]),
    str(r["tipo"]),
    999999999 if r["numero"] == "" else int(r["numero"])
))

campos = ["estado","lote","pv","tipo_num","tipo","numero","cuenta_pdf","cuenta_destino",
          "cuenta_origen","fecha","total","cae","archivo","ruta_relativa"]

with open(SALIDA, "w", newline="", encoding="utf-8") as fh:
    w = csv.DictWriter(fh, fieldnames=campos)
    w.writeheader()
    w.writerows(filas)

estados = Counter(r["estado"] for r in filas)
por_lote = Counter(int(r["lote"]) for r in filas if r["estado"] == "OK" and r["lote"] != "")

print("=== PDFs físicos julio 2026 vs FACTURAS.DBF ===")
print("Facturas KNG julio :", len(facturas))
print("PDF encontrados    :", sum(1 for r in filas if r["archivo"]))
for estado in sorted(estados):
    print(f"{estado:22}: {estados[estado]}")

print("\n=== Coincidencias exactas por lote ===")
for lote in sorted(por_lote):
    print(f"Lote {lote}: {por_lote[lote]}")

print("\nCSV:", SALIDA)
