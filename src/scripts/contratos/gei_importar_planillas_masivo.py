#!/usr/bin/env python3
"""GeI-Desarrollo: indexa el contenido COMPLETO de planillas .xls en PostgreSQL.

Se conserva cada XLS en su ubicación original; NO se copia, mueve ni renombra.
NO se crean expedientes nuevos ni se vinculan personas/contratos con conjeturas.

Requiere python3-xlrd en el anfitrión y contenedor gei-app en Desarrollo.

  python3 gei_importar_planillas_masivo.py --preparar
  python3 gei_importar_planillas_masivo.py --aplicar --limite 5
  python3 gei_importar_planillas_masivo.py --verificar
  python3 gei_importar_planillas_masivo.py --aplicar
  python3 gei_importar_planillas_masivo.py --verificar

Compatibilidad: reutiliza las claves y el formato de filas del importador
piloto del contrato 4332; no sobrescribe datos interpretados previos.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
import socket
import subprocess
import sys
from pathlib import Path

try:
    import xlrd
except ImportError:
    sys.exit('FALTA xlrd: sudo apt install python3-xlrd')

BASE = Path.home() / 'dockers/samba-server/privados/contratos'
PLAN = Path('/tmp/gei-contratos-xls-masivo-plan.json')
AVANCE = Path('/tmp/gei-contratos-xls-masivo-avance.json')


def guardar_privado(destino: Path, datos: dict) -> None:
    temporal = destino.with_name(destino.name + '.tmp-' + str(os.getpid()))
    fd = os.open(str(temporal), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(datos, f, ensure_ascii=False, indent=2, allow_nan=False)
            f.write('\n')
            f.flush()
            os.fsync(f.fileno())
        os.replace(temporal, destino)
    finally:
        if temporal.exists():
            temporal.unlink()


def cargar_json(ruta: Path) -> dict:
    with ruta.open(encoding='utf-8') as f:
        return json.load(f)


def checksum(ruta: Path) -> str:
    h = hashlib.sha256()
    with ruta.open('rb') as f:
        for porcion in iter(lambda: f.read(2 * 1024 * 1024), b''):
            h.update(porcion)
    return h.hexdigest()


def clave_planilla(rel: str) -> str:
    # Igual que gei_importar_piloto_4332.py.
    return hashlib.sha256(('GEI|PLANILLA|' + rel).encode('utf-8')).hexdigest()


def valor_celda(celda, datemode: int):
    if celda.ctype == xlrd.XL_CELL_DATE:
        try:
            d = xlrd.xldate_as_datetime(celda.value, datemode)
            return d.date().isoformat() if d.time() == dt.time() else d.isoformat()
        except (ValueError, OverflowError):
            # Fechas no representables: conservar el valor numérico ORIGINAL
            # junto con tipo_xlrd=3, sin inventar una fecha.
            return celda.value
    if celda.ctype in (xlrd.XL_CELL_EMPTY, xlrd.XL_CELL_BLANK):
        return ''
    if celda.ctype == xlrd.XL_CELL_BOOLEAN:
        return bool(celda.value)
    return celda.value


def excel_listado(base: Path) -> list[Path]:
    if not base.is_dir():
        raise RuntimeError('No existe el directorio de contratos: ' + str(base))
    encontrados = []
    for carpeta, subdirectorios, archivos in os.walk(base, followlinks=False):
        subdirectorios[:] = sorted(d for d in subdirectorios
                                   if not (Path(carpeta) / d).is_symlink())
        for nombre in sorted(archivos):
            if not nombre.lower().endswith('.xls'):
                continue
            p = Path(carpeta) / nombre
            if p.is_symlink() or not p.is_file():
                raise RuntimeError('XLS no regular: ' + str(p))
            encontrados.append(p)
    return sorted(encontrados, key=lambda p: p.relative_to(base).as_posix().casefold())


def leer_excel(archivo: Path, incluir_filas: bool) -> tuple[list[dict], list[dict]]:
    libro = xlrd.open_workbook(str(archivo), on_demand=True)
    resumen, filas = [], []
    try:
        for posicion in range(libro.nsheets):
            hoja = libro.sheet_by_index(posicion)
            resumen.append({'nombre': hoja.name, 'filas': hoja.nrows,
                            'columnas': hoja.ncols})
            if incluir_filas:
                for r in range(hoja.nrows):
                    celdas = [{'columna': c + 1,
                               'tipo_xlrd': int(hoja.cell(r, c).ctype),
                               'valor': valor_celda(hoja.cell(r, c), libro.datemode)}
                              for c in range(hoja.ncols)]
                    filas.append({'hoja': hoja.name, 'fila': r + 1, 'celdas': celdas})
            # Incluye hojas vacías en metadatos, como el importador piloto.
            try:
                libro.unload_sheet(posicion)
            except Exception:
                pass
    finally:
        libro.release_resources()
    return resumen, filas


def verificar_host() -> None:
    # Defensa adicional: los accesos a PostgreSQL se validan también desde PHP.
    if socket.gethostname().lower().split('.')[0] != 'gei-desarrollo':
        raise RuntimeError('Ejecutar únicamente desde gei-desarrollo')


PHP_PRECHECK = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
if (config('app.env') !== 'local' ||
    (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
    DB::connection()->getDatabaseName() !== 'gei_db') {
    fwrite(STDERR, "DESTINO NO AUTORIZADO\n"); exit(2);
}
foreach (['contratos_planillas', 'contratos_planillas_filas',
          'contratos_expedientes', 'contratos_documentos'] as $t) {
    if (!Schema::hasTable($t)) { fwrite(STDERR, "FALTA $t\n"); exit(3); }
}
echo "PRECHECK_OK\n";
'''

PHP_IMPORT = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
    if (config('app.env') !== 'local' ||
        (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
        DB::connection()->getDatabaseName() !== 'gei_db') {
        throw new RuntimeException('Destino PostgreSQL no autorizado');
    }
    $d = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $r = DB::transaction(function () use ($d) {
        $x = $d['xls'];
        $p = DB::table('contratos_planillas')->where('clave_origen', $x['clave_origen'])->first();
        if ($p) {
            if (trim((string)$p->sha256) !== $x['sha256'] ||
                (string)$p->ruta_origen !== $x['ruta_origen'] ||
                (string)$p->formato !== 'xls') {
                throw new RuntimeException('Planilla previa incompatible: '.$x['ruta_origen']);
            }
            $id = $p->id;
        } else {
            $id = DB::table('contratos_planillas')->insertGetId([
                'clave_origen' => $x['clave_origen'],
                'nombre_original' => $x['nombre_original'],
                'ruta_origen' => $x['ruta_origen'],
                'ruta_privada' => $x['ruta_origen'],
                'sha256' => $x['sha256'],
                'tamano_bytes' => $x['tamano_bytes'],
                'formato' => 'xls',
                'metadatos' => json_encode($x['metadatos'], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'importado_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // El piloto 4332 ya cargó sus 224 filas. Reutilizarlas sin borrar
        // datos_interpretados ni los vínculos documentales existentes.
        $previas = DB::table('contratos_planillas_filas')->where('planilla_id', $id)
            ->get(['nombre_hoja','numero_fila','celdas']);
        $indice = [];
        foreach ($previas as $fila) {
            $k = $fila->nombre_hoja."\x1f".$fila->numero_fila;
            $indice[$k] = is_string($fila->celdas)
                ? json_decode($fila->celdas, true, 512, JSON_THROW_ON_ERROR)
                : (array) $fila->celdas;
        }
        if (count($d['filas']) < count($indice)) {
            throw new RuntimeException('Menos filas que las ya registradas: '.$x['ruta_origen']);
        }
        $nuevas = []; $agregadas = 0; $comparadas = 0; $vistas = [];
        foreach ($d['filas'] as $fila) {
            $k = $fila['hoja']."\x1f".$fila['fila'];
            if (isset($vistas[$k])) throw new RuntimeException('Fila repetida en XLS: '.$k);
            $vistas[$k] = true;
            if (array_key_exists($k, $indice)) {
                if ($indice[$k] != $fila['celdas']) {
                    throw new RuntimeException('Contenido distinto en fila previamente importada: '.$k);
                }
                $comparadas++;
                continue;
            }
            $nuevas[] = [
                'planilla_id' => $id,
                'nombre_hoja' => $fila['hoja'],
                'numero_fila' => $fila['fila'],
                'celdas' => json_encode($fila['celdas'], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'datos_interpretados' => null,
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($nuevas) >= 100) {
                DB::table('contratos_planillas_filas')->insert($nuevas);
                $agregadas += count($nuevas);
                $nuevas = [];
            }
        }
        if ($nuevas) {
            DB::table('contratos_planillas_filas')->insert($nuevas);
            $agregadas += count($nuevas);
        }
        if (count($indice) !== $comparadas) {
            throw new RuntimeException('Hay filas antiguas ausentes en el XLS: '.$x['ruta_origen']);
        }
        return ['id_planilla'=>$id, 'filas_nuevas'=>$agregadas,
                'filas_anteriores'=>$comparadas,
                'filas_totales'=>count($d['filas'])];
    });
    echo 'PLANILLA_OK '.json_encode($r, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'PLANILLA_ERROR '.$ex->getMessage()."\n"); exit(1);
}
'''

PHP_VERIFICAR = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
    if (config('app.env') !== 'local' ||
        (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
        DB::connection()->getDatabaseName() !== 'gei_db') {
        throw new RuntimeException('Destino PostgreSQL no autorizado');
    }
    $xls = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $claves = array_column($xls, 'clave_origen');
    $existentes = DB::table('contratos_planillas')->whereIn('clave_origen', $claves)
        ->get(['id','clave_origen','sha256','ruta_origen'])->keyBy(fn ($p) => trim($p->clave_origen));
    $pendientes = []; $errores = []; $filas = 0;
    foreach ($xls as $x) {
        $p = $existentes->get($x['clave_origen']);
        if (!$p) { $pendientes[] = $x['ruta_origen']; continue; }
        $n = DB::table('contratos_planillas_filas')->where('planilla_id', $p->id)->count();
        if (trim((string)$p->sha256) !== $x['sha256'] ||
            (string)$p->ruta_origen !== $x['ruta_origen'] ||
            (int)$n !== (int)$x['filas']) {
            $errores[] = $x['ruta_origen'].' (filas BD='.$n.', XLS='.$x['filas'].')';
        } else { $filas += $n; }
    }
    echo 'VERIFICACION_XLS '.json_encode([
        'total'=>count($xls),'completas'=>count($xls)-count($pendientes)-count($errores),
        'pendientes'=>count($pendientes),'errores'=>count($errores),
        'filas_verificadas'=>$filas,
        'ejemplos_pendientes'=>array_slice($pendientes, 0, 5),
        'ejemplos_errores'=>array_slice($errores, 0, 10),
    ], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    if ($errores) exit(2);
} catch (Throwable $ex) {
    fwrite(STDERR, 'VERIFICACION_ERROR '.$ex->getMessage()."\n"); exit(1);
}
'''


def docker_php(codigo: str, entrada: dict | list | None = None) -> str:
    datos = (json.dumps(entrada, ensure_ascii=False, allow_nan=False).encode('utf-8')
             if entrada is not None else b'')
    p = subprocess.run(['docker', 'exec', '-i', 'gei-app', 'php', '-r', codigo],
                       input=datos, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    salida = p.stdout.decode('utf-8', 'replace').strip()
    if p.returncode:
        raise RuntimeError('PHP/BD devolvió código '+str(p.returncode)+'\n'
                           +salida+'\n'+p.stderr.decode('utf-8','replace'))
    return salida


def preparar() -> None:
    verificar_host()
    archivos = excel_listado(BASE)
    metas = []
    fallos = []
    for posicion, ruta in enumerate(archivos, 1):
        rel = ruta.relative_to(BASE).as_posix()
        try:
            hojas, _ = leer_excel(ruta, False)
            metas.append({'ruta_origen':rel,'nombre_original':ruta.name,
                          'clave_origen':clave_planilla(rel),
                          'sha256':checksum(ruta), 'tamano_bytes':ruta.stat().st_size,
                          'hojas':hojas, 'filas':sum(h['filas'] for h in hojas)})
        except Exception as e:
            fallos.append({'archivo':rel, 'error':str(e)})
        if posicion % 25 == 0 or posicion == len(archivos):
            print(f'Analizadas: {posicion}/{len(archivos)}', flush=True)
    plan = {'version':1, 'cantidad_encontrada':len(archivos),
            'planillas':metas,'errores_lectura':fallos}
    guardar_privado(PLAN, plan)
    print('PLAN_PREPARADO:', PLAN)
    print(json.dumps({'xls_encontrados':len(archivos),'xls_legibles':len(metas),
                      'errores_lectura':len(fallos),'hojas':sum(len(x['hojas']) for x in metas),
                      'filas':sum(x['filas'] for x in metas),
                      'ejemplos_error':fallos[:15]}, ensure_ascii=False, indent=2))
    print('No se modificaron archivos originales ni PostgreSQL.')
    if fallos:
        sys.exit('ATENCION: hay XLS ilegibles; resolver antes de --aplicar.')


def comprobar_plan() -> list[dict]:
    if not PLAN.exists():
        raise RuntimeError('Primero ejecutar --preparar')
    plan = cargar_json(PLAN)
    if plan.get('version') != 1 or plan.get('errores_lectura'):
        raise RuntimeError('Plan inválido o con errores de lectura')
    existentes = [p.relative_to(BASE).as_posix() for p in excel_listado(BASE)]
    previstas = [x['ruta_origen'] for x in plan['planillas']]
    if set(existentes) != set(previstas) or len(existentes) != len(previstas):
        raise RuntimeError('Cambió el inventario XLS desde --preparar. No aplicar.')
    return plan['planillas']


def aplicar(limite: int | None) -> None:
    verificar_host()
    plan = comprobar_plan()
    print(docker_php(PHP_PRECHECK))
    avance = cargar_json(AVANCE) if AVANCE.exists() else {'version':1, 'completados':{}}
    if avance.get('version') != 1:
        raise RuntimeError('Archivo de avance con versión incompatible')
    hechos = avance['completados']
    pendientes = [x for x in plan if hechos.get(x['ruta_origen']) != x['sha256']]
    print(f'PLANILLAS: {len(plan)} | avance confirmado: {len(plan)-len(pendientes)}'
          f' | pendientes: {len(pendientes)} | límite: {limite or "sin límite"}', flush=True)
    procesadas = 0
    for x in pendientes:
        if limite is not None and procesadas >= limite:
            break
        ruta = BASE / x['ruta_origen']
        if not ruta.is_file() or checksum(ruta) != x['sha256']:
            raise RuntimeError('XLS falta o cambió desde el inventario: '+x['ruta_origen'])
        hojas, filas = leer_excel(ruta, True)
        if hojas != x['hojas'] or len(filas) != x['filas']:
            raise RuntimeError('Estructura XLS cambió: '+x['ruta_origen'])
        payload = {'xls':{'clave_origen':x['clave_origen'],
                          'nombre_original':x['nombre_original'],
                          'ruta_origen':x['ruta_origen'],
                          'sha256':x['sha256'],'tamano_bytes':x['tamano_bytes'],
                          'metadatos':{'hojas':hojas,'observacion':
                              'Archivo XLS original conservado in situ; todas las hojas y filas extraídas.'}},
                   'filas':filas}
        try:
            salida = docker_php(PHP_IMPORT, payload)
        except Exception as e:
            print('ERROR en:',x['ruta_origen'],file=sys.stderr)
            raise
        if not salida.startswith('PLANILLA_OK '):
            raise RuntimeError('Respuesta inesperada de PHP: '+salida)
        hechos[x['ruta_origen']] = x['sha256']
        guardar_privado(AVANCE, avance)
        procesadas += 1
        print(f'OK {procesadas} | {x["ruta_origen"]} | {salida}', flush=True)
    print('IMPORTACION_XLS_OK '+json.dumps({'procesadas_esta_ejecucion':procesadas,
           'completadas_segun_avance':len(hechos),'total_planillas':len(plan)},
           ensure_ascii=False))


def verificar() -> None:
    verificar_host()
    plan = comprobar_plan()
    print(docker_php(PHP_PRECHECK))
    entrada = [{'clave_origen':x['clave_origen'], 'sha256':x['sha256'],
                'ruta_origen':x['ruta_origen'],'filas':x['filas']} for x in plan]
    salida = docker_php(PHP_VERIFICAR, entrada)
    print(salida)
    resultado = json.loads(salida.split(' ',1)[1])
    if resultado['errores']:
        raise RuntimeError('Hay inconsistencias en los registros de planillas.')
    if resultado['pendientes']:
        print('VERIFICACION_PARCIAL: faltan planillas por importar.')
    else:
        print('TODAS_LAS_PLANILLAS_REGISTRADAS; sin mover ni duplicar los XLS.')


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__)
    grupo = p.add_mutually_exclusive_group(required=True)
    grupo.add_argument('--preparar', action='store_true')
    grupo.add_argument('--aplicar', action='store_true')
    grupo.add_argument('--verificar', action='store_true')
    p.add_argument('--limite', type=int)
    args = p.parse_args()
    if args.limite is not None and (not args.aplicar or args.limite <= 0):
        p.error('--limite solo se usa con --aplicar y debe ser mayor que cero')
    try:
        if args.preparar: preparar()
        elif args.aplicar: aplicar(args.limite)
        else: verificar()
    except Exception as e:
        sys.exit('ERROR: '+str(e))


if __name__ == '__main__':
    main()
