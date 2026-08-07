import tempfile
import os
from smbclient import open_file, register_session
from dbfread import DBF

SERVIDOR = "192.168.50.217"
RECURSO = "Archivos_Server"
TABLA_PATH = r"KNG\facturas.DBF"

USUARIO = "gei"
PASSWORD = "gei"

def leer_facturas():
    tmp_dbf_path = None

    try:
        # Conectar al servidor SMB
        register_session(SERVIDOR, username=USUARIO, password=PASSWORD)

        ruta_dbf = rf"\\{SERVIDOR}\{RECURSO}\{TABLA_PATH}"

        # Descargar el archivo DBF como bytes
        with open_file(ruta_dbf, mode="rb") as f_remote:
            dbf_bytes = f_remote.read()

        # Guardar los bytes en un archivo temporal
        tmp_dbf = tempfile.NamedTemporaryFile(delete=False, suffix=".dbf")
        tmp_dbf.write(dbf_bytes)
        tmp_dbf.close()
        tmp_dbf_path = tmp_dbf.name

        # Leer el DBF con dbfread (sin memo, campos memo serán None)
        tabla = DBF(tmp_dbf_path, encoding='cp1252')

        print("--- PRIMEROS 10 REGISTROS ---")
        for i, registro in enumerate(tabla):
            if i >= 10:
                break
            print(f"Registro {i + 1}: {dict(registro)}")

    except Exception as e:
        print(f"Error: {e}")
        import traceback
        traceback.print_exc()

    finally:
        # Eliminar archivo temporal
        if tmp_dbf_path and os.path.exists(tmp_dbf_path):
            os.unlink(tmp_dbf_path)

if __name__ == "__main__":
    leer_facturas()
