from dbfread import DBF
import sys

archivo = sys.argv[1]

tabla = DBF(
    archivo,
    load=False,
    char_decode_errors="ignore",
)

print("Archivo:", archivo)
print("Encoding:", tabla.encoding)
print("Campos:")
for f in tabla.fields:
    print(
        f"  {f.name:20} "
        f"tipo={f.type} "
        f"long={f.length} "
        f"dec={f.decimal_count}"
    )

print("\nPrimeros registros:")
for i, reg in enumerate(tabla):
    print(dict(reg))
    if i >= 4:
        break
