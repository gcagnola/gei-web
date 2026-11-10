#!/usr/bin/env python3
"""GeI-Desarrollo: importa XLS completo y 4 PDF del expediente 4332, sin copiar archivos.

Uso:
  python3 /tmp/gei_importar_piloto_4332.py            # solo validar y mostrar
  python3 /tmp/gei_importar_piloto_4332.py --aplicar  # insertar (idempotente)

Ejecutar en el anfitrión gei-desarrollo, con xlrd instalado y Docker gei-app disponible.
"""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import pathlib
import subprocess
import sys

try:
    import xlrd
except ImportError:
    sys.exit("FALTA xlrd: sudo apt install python3-xlrd")

BASE_REL_XLS = "REGISTRO_SELLADO_DE_CONTRATOS/2026/REG CONTR 1ra QUINC.AGO. 2026.xls"
NORMALIZADO = "2026/08/4332/1103-23906-03"
ORIGINAL = "2026/8.  AGOSTO STA FE/1º QUINCENA/4332 - CALLIGARO MARCELO - TOMATIS FELIX"
ARCHIVOS = (
    ("CONTRATO", "contrato.pdf", "4332.pdf"),
    ("AUT", "aut.pdf", "4332 - AUT.pdf"),
    ("HONOR", "honor.pdf", "4332 - HONOR.pdf"),
    ("PLANILLA", "planilla.pdf", "4332 - PLANILLA.pdf"),
)


def sha256_archivo(ruta: pathlib.Path) -> str:
    h = hashlib.sha256()
    with ruta.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def clave(tipo: str, identificador: str) -> str:
    return hashlib.sha256(f"GEI|{tipo}|{identificador}".encode("utf-8")).hexdigest()


def valor_celda(celda: xlrd.sheet.Cell, datemode: int):
    if celda.ctype == xlrd.XL_CELL_DATE:
        dato = xlrd.xldate_as_datetime(celda.value, datemode)
        return dato.date().isoformat() if dato.time() == dt.time() else dato.isoformat()
    if celda.ctype == xlrd.XL_CELL_NUMBER:
        # No convertir todos los números a texto: preservar los valores numéricos.
        return celda.value
    if celda.ctype in (xlrd.XL_CELL_EMPTY, xlrd.XL_CELL_BLANK):
        return ""
    if celda.ctype == xlrd.XL_CELL_BOOLEAN:
        return bool(celda.value)
    return celda.value


def fecha(dato: object) -> str:
    if isinstance(dato, str):
        try:
            return dt.date.fromisoformat(dato[:10]).isoformat()
        except ValueError:
            return dt.datetime.strptime(dato.strip(), "%d/%m/%Y").date().isoformat()
    raise ValueError(f"Fecha XLS sin formato reconocible: {dato!r}")


def cadena_enteros(dato: object) -> str:
    if isinstance(dato, (int, float)):
        if int(dato) != dato:
            raise ValueError(f"Número no entero: {dato!r}")
        return str(int(dato))
    cadena = str(dato).strip()
    return cadena[:-2] if cadena.endswith(".0") else cadena


# Se pasa el manifiesto JSON por stdin al PHP del contenedor. No se crea copia de PDF/XLS.
# Bootstrap Laravel: usa exactamente la configuración de la aplicación gei-app.
PHP_IMPORTAR = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

try {
    $d = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $host = (string) config('database.connections.pgsql.host');
    $db = DB::connection()->getDatabaseName();
    if (config('app.env') !== 'local' || $host !== '192.168.50.13' || $db !== 'gei_db') {
        throw new RuntimeException("DESTINO NO AUTORIZADO: app.env=".config('app.env')." host=$host base=$db");
    }

    $res = DB::transaction(function () use ($d) {
        foreach (['contratos_planillas','contratos_planillas_filas','contratos_expedientes','contratos_documentos'] as $t) {
            if (!Illuminate\Support\Facades\Schema::hasTable($t)) {
                throw new RuntimeException("Falta tabla $t");
            }
        }
        $xls = $d['xls'];
        $plan = DB::table('contratos_planillas')->where('clave_origen', $xls['clave_origen'])->first();
        if ($plan && trim($plan->sha256) !== $xls['sha256']) {
            throw new RuntimeException('El Excel original cambió: abortado para revisión');
        }
        if (!$plan) {
            $idPlan = DB::table('contratos_planillas')->insertGetId([
                'clave_origen' => $xls['clave_origen'],
                'nombre_original' => $xls['nombre_original'],
                'ruta_origen' => $xls['ruta_relativa'],
                'ruta_privada' => $xls['ruta_relativa'],
                'sha256' => $xls['sha256'],
                'tamano_bytes' => $xls['tamano_bytes'],
                'formato' => 'xls',
                'metadatos' => json_encode($xls['metadatos'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'importado_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $idPlan = $plan->id;
        }

        $filaId = null;
        $agregadas = 0;
        foreach ($d['filas'] as $f) {
            $existente = DB::table('contratos_planillas_filas')
                ->where('planilla_id', $idPlan)
                ->where('nombre_hoja', $f['hoja'])
                ->where('numero_fila', $f['fila'])->first();
            $celdas = json_encode($f['celdas'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($existente) {
                // El archivo XLS tiene SHA-256 estable; la fila debe conservar su lectura.
                if (json_decode($existente->celdas, true) != $f['celdas']) {
                    throw new RuntimeException("Fila diferente a la guardada: {$f['hoja']}!{$f['fila']}");
                }
                $idFila = $existente->id;
            } else {
                $idFila = DB::table('contratos_planillas_filas')->insertGetId([
                    'planilla_id' => $idPlan,
                    'nombre_hoja' => $f['hoja'],
                    'numero_fila' => $f['fila'],
                    'celdas' => $celdas,
                    'datos_interpretados' => $f['hoja'] === 'DETALLE' && $f['fila'] === 6
                        ? json_encode($d['expediente']['datos_interpretados'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                        : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $agregadas++;
            }
            if ($f['hoja'] === 'DETALLE' && $f['fila'] === 6) $filaId = $idFila;
        }
        if ($filaId === null) throw new RuntimeException('No aparece DETALLE fila 6');

        $co = DB::table('contratos')->where('id', 13403)->first();
        if (!$co || $co->cuenta_inquilino !== '11032390603' ||
            $co->cuenta_propietario !== '12020912010' ||
            (string)$co->fecha_celebracion !== '2026-08-03' ||
            (string)$co->fecha_fin !== '2027-08-31' || $co->estado !== 'ACTIVO') {
            throw new RuntimeException('Contrato COBOL 13403 no coincide en cuentas/fechas/estado');
        }
        $ccInq = DB::table('clientes_cuentas')->where('cuenta', '11032390603')->where('rol', 'INQUILINO')->get();
        if ($ccInq->count() !== 1 || (int)$ccInq[0]->cliente_id !== 15710) {
            throw new RuntimeException('Cuenta inquilino sin asociación única esperada al cliente 15710');
        }
        $ccProp = DB::table('clientes_cuentas')->where('cuenta', '12020912010')->where('rol', 'PROPIETARIO')->get();
        // Si la cuenta de propietario no es inequívoca, NO publicar la vinculación.
        $propId = $ccProp->count() === 1 ? $ccProp[0]->id : null;
        $inm = DB::table('contratos_inmuebles')->where('contrato_id', 13403)->distinct()->pluck('inmueble_id');
        $inmId = $inm->count() === 1 ? $inm[0] : null;

        $e = $d['expediente'];
        $existente = DB::table('contratos_expedientes')->where('clave_origen', $e['clave_origen'])->first();
        if ($existente && ((string)$existente->numero_documental !== '4332' ||
            (int)$existente->contrato_id !== 13403 ||
            (string)$existente->cuenta_inquilino !== '11032390603')) {
            throw new RuntimeException('Expediente existente con datos incompatibles');
        }
        if (!$existente) {
            $idExp = DB::table('contratos_expedientes')->insertGetId([
                'clave_origen' => $e['clave_origen'],
                'numero_documental' => '4332', 'anio_archivo' => 2026, 'mes_archivo' => 8,
                'sucursal_id' => $ccInq[0]->sucursal_id ?? null,
                'fecha_celebracion' => $e['fecha_celebracion'],
                'fecha_inicio' => $e['fecha_inicio'],
                'fecha_fin' => $e['fecha_fin'],
                'cuenta_inquilino_planilla' => $e['cuenta_inquilino_planilla'],
                'cuenta_inquilino' => '11032390603',
                'cuenta_propietario' => '12020912010',
                'cuit_propietario' => $e['cuit_propietario'],
                'seguro_marca_original' => $e['seguro_marca_original'],
                'seguro_marcado' => $e['seguro_marcado'],
                'datos_excel_originales' => json_encode($e['datos_interpretados'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ruta_carpeta_origen' => $e['ruta_carpeta_origen'],
                'ruta_relativa_normalizada' => $e['ruta_relativa_normalizada'],
                'planilla_fila_id' => $filaId,
                'contrato_id' => 13403,
                'cliente_cuenta_inquilino_id' => $ccInq[0]->id,
                'cliente_cuenta_propietario_id' => $propId,
                'inmueble_id' => $inmId,
                // Integridad documental y fecha COBOL discrepante: no publicar todavía.
                'estado_vinculacion' => 'PENDIENTE',
                'observaciones_revision' => 'Inicio Excel 2026-09-01 / inicio COBOL 2021-09-01. Revisar enlace inmueble y habilitación de acceso antes de publicar.',
                'importado_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $idExp = $existente->id;
        }

        $nuevosDocs = 0;
        foreach ($d['documentos'] as $doc) {
            $anterior = DB::table('contratos_documentos')->where('clave_origen', $doc['clave_origen'])->first();
            if ($anterior) {
                if (trim($anterior->sha256) !== $doc['sha256'] || (int)$anterior->expediente_id !== (int)$idExp) {
                    throw new RuntimeException("Documento cambiado: {$doc['ruta_privada']}");
                }
                continue;
            }
            DB::table('contratos_documentos')->insert([
                'expediente_id' => $idExp,
                'clave_origen' => $doc['clave_origen'],
                'tipo' => $doc['tipo'],
                'nombre_original' => $doc['nombre_original'],
                'ruta_origen' => $doc['ruta_origen'],
                'ruta_privada' => $doc['ruta_privada'],
                'sha256' => $doc['sha256'],
                'tamano_bytes' => $doc['tamano_bytes'],
                'mime' => 'application/pdf',
                'orden' => $doc['orden'],
                'visible_inquilino' => false,
                'visible_propietario' => false,
                'estado_verificacion' => 'PENDIENTE',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $nuevosDocs++;
        }
        return compact('idPlan', 'filaId', 'idExp', 'agregadas', 'nuevosDocs', 'propId', 'inmId');
    });
    echo "IMPORTACION_OK ".json_encode($res, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $ex) {
    fwrite(STDERR, "IMPORTACION_ERROR: ".$ex->getMessage().PHP_EOL);
    exit(1);
}
'''


def preparar(base: pathlib.Path) -> dict:
    archivo = base / BASE_REL_XLS
    if not archivo.is_file():
        raise FileNotFoundError(f"No existe planilla: {archivo}")
    libro = xlrd.open_workbook(str(archivo))
    detalle = libro.sheet_by_name("DETALLE")
    if detalle.nrows < 6 or detalle.ncols != 20:
        raise ValueError("Estructura inesperada de DETALLE; no se importa")
    fila6 = [valor_celda(detalle.cell(5, i), libro.datemode) for i in range(20)]
    numero = cadena_enteros(fila6[0])
    cuenta = str(fila6[2]).strip()
    cuit = cadena_enteros(fila6[16])
    fcele = fecha(fila6[7]); finicio = fecha(fila6[8]); ffin = fecha(fila6[19])
    seguro = str(fila6[4]).strip()
    if (numero, cuenta, cuit, fcele, finicio, ffin) != (
        "4332", "23906/03", "20108633442", "2026-08-03", "2026-09-01", "2027-08-31"
    ):
        raise ValueError(f"La fila 6 NO coincide con el piloto: {numero} {cuenta} {cuit} {fcele} {finicio} {ffin}")
    if fecha(fila6[15]) != fcele:
        raise ValueError("La segunda fecha de celebración (col. 16) difiere de la col. 8")
    if cadena_enteros(fila6[9]) != "70000":
        raise ValueError("Importe esperado de contrato 4332 no coincide")
    filas = []
    resumen = []
    for hoja in libro.sheets():
        resumen.append({"nombre": hoja.name, "filas": hoja.nrows, "columnas": hoja.ncols})
        for n in range(hoja.nrows):
            filas.append({
                "hoja": hoja.name,
                "fila": n + 1,
                "celdas": [
                    {"columna": c + 1, "tipo_xlrd": int(hoja.cell(n, c).ctype),
                     "valor": valor_celda(hoja.cell(n, c), libro.datemode)}
                    for c in range(hoja.ncols)
                ],
            })
    docs = []
    for orden, (tipo, nombre, antiguo) in enumerate(ARCHIVOS, 1):
        ruta_rel = f"{NORMALIZADO}/{nombre}"
        ruta = base / ruta_rel
        if not ruta.is_file():
            raise FileNotFoundError(f"Falta el PDF ya MOVIDO: {ruta}")
        docs.append({
            "clave_origen": clave("DOCUMENTO", f"{ORIGINAL}/{antiguo}"),
            "tipo": tipo,
            "nombre_original": antiguo,
            "ruta_origen": f"{ORIGINAL}/{antiguo}",
            "ruta_privada": ruta_rel,
            "sha256": sha256_archivo(ruta),
            "tamano_bytes": ruta.stat().st_size,
            "orden": orden,
        })
    metadatos = {
        "hojas": resumen,
        "observacion": "XLS original se conserva donde estaba; se extraen todas las hojas y filas.",
    }
    interpretados = {
        "hoja": "DETALLE", "numero_fila": 6,
        "numero_documental": numero,
        "inquilino_nombre": str(fila6[5]).strip(),
        "inmueble_domicilio": str(fila6[6]).strip(),
        "propietario_nombre": str(fila6[17]).strip(),
        "cuit_propietario": cuit,
        "cuenta_inquilino_planilla": cuenta,
        "fecha_celebracion": fcele,
        "fecha_inicio": finicio,
        "fecha_fin": ffin,
        "alquiler_inicial": fila6[9],
        "seguro_marca_original": seguro,
        "seguro_marcado": seguro == "*",
        "columnas_originales": fila6,
    }
    return {
        "xls": {
            "clave_origen": clave("PLANILLA", BASE_REL_XLS),
            "nombre_original": archivo.name,
            "ruta_relativa": BASE_REL_XLS,
            "sha256": sha256_archivo(archivo),
            "tamano_bytes": archivo.stat().st_size,
            "metadatos": metadatos,
        },
        "filas": filas,
        "expediente": {
            "clave_origen": clave("EXPEDIENTE", f"{BASE_REL_XLS}|DETALLE|6|4332"),
            "fecha_celebracion": fcele,
            "fecha_inicio": finicio,
            "fecha_fin": ffin,
            "cuenta_inquilino_planilla": cuenta,
            "cuit_propietario": cuit,
            "seguro_marca_original": seguro,
            "seguro_marcado": seguro == "*",
            "datos_interpretados": interpretados,
            "ruta_carpeta_origen": ORIGINAL,
            "ruta_relativa_normalizada": NORMALIZADO,
        },
        "documentos": docs,
    }


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--base", type=pathlib.Path,
                   default=pathlib.Path.home() / "dockers/samba-server/privados/contratos")
    p.add_argument("--aplicar", action="store_true", help="Registrar en PostgreSQL de Desarrollo")
    args = p.parse_args()
    try:
        manifiesto = preparar(args.base)
    except Exception as ex:
        sys.exit(f"ERROR DE VALIDACION: {ex}")

    print("===== PILOTO 4332 =====")
    print("PLANILLA:", manifiesto['xls']['ruta_relativa'])
    print("HOJAS:", ", ".join(f"{h['nombre']} ({h['filas']} filas)" for h in manifiesto['xls']['metadatos']['hojas']))
    print("FILAS A REGISTRAR:", len(manifiesto['filas']))
    print("PDF EXISTENTES:", len(manifiesto['documentos']))
    print("CUENTA INQUILINO: 11032390603; CONTRATO OPERATIVO: 13403")
    print("SEGURO:", repr(manifiesto['expediente']['seguro_marca_original']))
    print("NOTA: fecha inicio documental 2026-09-01; COBOL 2021-09-01 (se conservará)")
    if not args.aplicar:
        print("MODO CONSULTA: no se modificaron archivos ni PostgreSQL. Para aplicar: --aplicar")
        return

    entrada = json.dumps(manifiesto, ensure_ascii=False, allow_nan=False).encode('utf-8')
    print("===== REGISTRANDO EN POSTGRESQL DE DESARROLLO =====", flush=True)
    proceso = subprocess.run(
        ["docker", "exec", "-i", "gei-app", "php", "-r", PHP_IMPORTAR],
        input=entrada, capture_output=True,
    )
    if proceso.stdout:
        print(proceso.stdout.decode('utf-8', 'replace'), end='')
    if proceso.stderr:
        print(proceso.stderr.decode('utf-8', 'replace'), file=sys.stderr, end='')
    if proceso.returncode:
        sys.exit(f"ERROR: se canceló la importación (código {proceso.returncode})")


if __name__ == "__main__":
    main()
