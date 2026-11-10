#!/usr/bin/env python3
"""GeI-Desarrollo: aplicar fechas documentales cotejadas V7+V8, sin autorizar PDF.

Uso:
    python3 /tmp/gei_aplicar_fechas_documentales_v9.py
    python3 /tmp/gei_aplicar_fechas_documentales_v9.py --aplicar --limite 50
    python3 /tmp/gei_aplicar_fechas_documentales_v9.py --verificar
    python3 /tmp/gei_aplicar_fechas_documentales_v9.py --aplicar
    python3 /tmp/gei_aplicar_fechas_documentales_v9.py --verificar

NO modifica contratos COBOL, cuentas, inmuebles, estado_vinculacion,
permisos de PDF, archivos, ni las celdas originales de planillas.
Requiere coincidencia exacta entre V7, V8, XLS y BD. NO usa nombres.
"""
from __future__ import annotations

import argparse
import collections
import csv
import datetime as dt
import json
import re
import socket
import subprocess
import sys
import unicodedata
from pathlib import Path

import xlrd

V7 = Path('/tmp/gei-diagnostico-fechas-documentales-v7.csv')
V8 = Path('/tmp/gei-verificacion-origen-fechas-v8.csv')
BASE = Path.home() / 'dockers/samba-server/privados/contratos'
REPORTE = Path('/tmp/gei-fechas-documentales-v9-excluidos.csv')
ACEPTADOS = {'PROPUESTA_ESTANDAR', 'PROPUESTA_FIRMA_POSTERIOR'}
BATCH = 100

PHP = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
try {
    if (config('app.env') !== 'local' ||
        (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
        DB::connection()->getDatabaseName() !== 'gei_db') {
        throw new RuntimeException('Destino no autorizado: únicamente gei-desarrollo/192.168.50.13/gei_db');
    }
    $payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $modo = $payload['modo'] ?? null;
    $filas = $payload['filas'] ?? null;
    if (!in_array($modo, ['aplicar', 'verificar'], true) || !is_array($filas) || count($filas) > 100) {
        throw new RuntimeException('Solicitud o lote inválido');
    }
    $resultado = DB::transaction(function () use ($filas, $modo) {
        $r = ['nuevas' => 0, 'ya_existentes' => 0, 'pendientes' => 0, 'errores' => 0];
        foreach ($filas as $dato) {
            $id = (int) ($dato['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('ID inválido');
            $e = DB::table('contratos_expedientes')->where('id', $id)
                ->when($modo === 'aplicar', fn($q) => $q->lockForUpdate())
                ->first();
            if (!$e || $e->estado_vinculacion !== 'PENDIENTE') {
                throw new RuntimeException('Expediente ausente o dejó de estar PENDIENTE: '.$id);
            }
            $filaId = (int)$dato['fila_id'];
            $cuentaId = (int)$e->cliente_cuenta_inquilino_id;
            if ((int)$e->planilla_fila_id !== $filaId ||
                (string)$e->cuenta_inquilino !== (string)$dato['cuenta'] ||
                $cuentaId < 1 ||
                (string)$e->numero_documental !== (string)$dato['numero']) {
                throw new RuntimeException('Vínculo documental o cuenta cambió: '.$id);
            }
            $cc = DB::table('clientes_cuentas')->where('id', $cuentaId)->first();
            if (!$cc || $cc->rol !== 'INQUILINO' || (string)$cc->cuenta !== (string)$dato['cuenta']) {
                throw new RuntimeException('Cuenta maestra incorrecta: '.$id);
            }
            $f = DB::table('contratos_planillas_filas')->where('id', $filaId)->first();
            if (!$f || mb_strtoupper(trim((string)$f->nombre_hoja)) !== 'DETALLE' ||
                (int)$f->numero_fila !== (int)$dato['fila_excel']) {
                throw new RuntimeException('Fila DETALLE distinta: '.$id);
            }
            $p = DB::table('contratos_planillas')->where('id', $f->planilla_id)->first();
            if (!$p || (string)$p->ruta_origen !== (string)$dato['excel_origen']) {
                throw new RuntimeException('Planilla distinta: '.$id);
            }
            $celdas = is_string($f->celdas)
                ? json_decode($f->celdas, true, 512, JSON_THROW_ON_ERROR) : $f->celdas;
            if (!is_array($celdas)) throw new RuntimeException('Celdas inválidas: '.$id);
            $primera = $celdas[0]['valor'] ?? null;
            if (!is_numeric($primera) ||
                (ltrim((string)(int)$primera, '0') ?: '0') !==
                (ltrim((string)$dato['numero'], '0') ?: '0')) {
                throw new RuntimeException('Número documental Excel distinto: '.$id);
            }
            foreach ($dato['celdas_fecha'] as $col => $val) {
                $pos = (int)$col;
                $celda = $celdas[$pos - 1] ?? null;
                if (!is_array($celda) || (int)($celda['columna'] ?? 0) !== $pos ||
                    (int)($celda['tipo_xlrd'] ?? 0) !== 3 ||
                    (string)($celda['valor'] ?? '') !== (string)$val) {
                    throw new RuntimeException('Fecha Excel en BD distinta: '.$id.' col '.$col);
                }
            }
            $n = DB::table('contratos_documentos')->where('expediente_id', $id)
                ->where('tipo', 'CONTRATO')->count();
            if ($n !== 1) throw new RuntimeException('PDF principal dejó de ser único: '.$id);
            $esperadas = [
                'fecha_celebracion' => $dato['celebracion'],
                'fecha_inicio' => $dato['inicio'],
                'fecha_fin' => $dato['fin'],
            ];
            $completas = 0;
            foreach ($esperadas as $campo => $valor) {
                $previo = $e->$campo === null ? null : substr((string)$e->$campo, 0, 10);
                if ($previo !== null && $previo !== $valor) {
                    throw new RuntimeException('Conflicto con fecha existente '.$campo.' exp '.$id);
                }
                if ($previo === $valor) $completas++;
            }
            if ($completas === 3) {
                $r['ya_existentes']++;
            } elseif ($modo === 'verificar') {
                $r['pendientes']++;
            } else {
                DB::table('contratos_expedientes')->where('id', $id)
                    ->update($esperadas + ['updated_at' => now()]);
                $r['nuevas']++;
            }
        }
        return $r;
    });
    echo 'LOTE_OK '.json_encode($resultado, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'LOTE_ERROR: '.$e->getMessage()."\n");
    exit(1);
}
'''


def leer_csv(ruta: Path, campos: set[str]) -> list[dict[str, str]]:
    if not ruta.is_file():
        raise RuntimeError(f'Falta {ruta}; no regenerar informes después de comenzar a aplicar sin revisar')
    with ruta.open(encoding='utf-8-sig', newline='') as fh:
        lector = csv.DictReader(fh)
        if not lector.fieldnames or not campos.issubset(lector.fieldnames):
            raise RuntimeError(f'Cabecera inesperada en {ruta}')
        filas = list(lector)
    ids = [int(x['expediente_id']) for x in filas]
    if len(ids) != len(set(ids)):
        raise RuntimeError(f'IDs duplicados en {ruta}')
    return filas


def txt(valor: object) -> str:
    x = unicodedata.normalize('NFKD', str(valor))
    x = ''.join(c for c in x if not unicodedata.combining(c))
    return re.sub(r'\s+', ' ', x).strip().upper()


def columna_rotulo(hoja: xlrd.sheet.Sheet, col: int) -> str:
    partes = []
    for fila in range(min(5, hoja.nrows)):
        c = hoja.cell(fila, col - 1)
        if c.ctype == xlrd.XL_CELL_TEXT and str(c.value).strip():
            partes.append(txt(c.value))
    return ' '.join(partes)


def validar_encabezados(hoja: xlrd.sheet.Sheet, celebracion: int, inicio: int, fin: int) -> None:
    h_c = columna_rotulo(hoja, celebracion)
    h_i = columna_rotulo(hoja, inicio)
    h_f = columna_rotulo(hoja, fin)
    if not re.search(r'CELEBRA', h_c):
        raise ValueError(f'ENCABEZADO_CELEBRACION_NO_RECONOCIDO_C{celebracion}')
    if not re.search(r'VIGENC', h_i):
        raise ValueError(f'ENCABEZADO_VIGENCIA_NO_RECONOCIDO_C{inicio}')
    if not re.search(r'VTO|VENC', h_f) or not re.search(r'CONTRAT', h_f):
        raise ValueError(f'ENCABEZADO_VENCIMIENTO_NO_RECONOCIDO_C{fin}')


def parsear_celdas(s: str) -> list[tuple[int, str]]:
    resultado = []
    for parte in s.split(' | '):
        if not parte.strip():
            continue
        m = re.fullmatch(r'(\d+):(\d{4}-\d{2}-\d{2})', parte.strip())
        if not m:
            raise ValueError('POSICIONES_INESPERADAS')
        resultado.append((int(m[1]), m[2]))
    if len(resultado) not in (3, 4):
        raise ValueError('CANTIDAD_FECHAS_INESPERADA')
    return resultado


def origen_xls(r: dict[str, str], libros: dict[str, xlrd.book.Book]) -> list[tuple[int, str]]:
    rel = Path(r['excel_origen'])
    if rel.is_absolute() or '..' in rel.parts:
        raise ValueError('RUTA_XLS_INSEGURA')
    ruta = (BASE / rel).resolve()
    if not ruta.is_relative_to(BASE.resolve()) or not ruta.is_file():
        raise ValueError('XLS_NO_ENCONTRADO')
    clave = str(ruta)
    if clave not in libros:
        libros[clave] = xlrd.open_workbook(clave, on_demand=True)
    libro = libros[clave]
    nombre = next((s for s in libro.sheet_names() if s.strip().upper() == 'DETALLE'), None)
    if nombre is None:
        raise ValueError('SIN_HOJA_DETALLE')
    hoja = libro.sheet_by_name(nombre)
    fila = int(r['fila_excel'])
    celdas = parsear_celdas(r['columnas_fecha_y_valores'])
    if fila < 1 or fila > hoja.nrows:
        raise ValueError('FILA_FUERA_DE_RANGO')
    if int(r['total_columnas']) != hoja.ncols:
        raise ValueError('TOTAL_COLUMNAS_DISTINTO')
    for col, iso in celdas:
        if col < 1 or col > hoja.ncols:
            raise ValueError('COLUMNA_FUERA_DE_RANGO')
        celda = hoja.cell(fila - 1, col - 1)
        if celda.ctype != xlrd.XL_CELL_DATE:
            raise ValueError('CELDA_NO_FECHA')
        fecha = xlrd.xldate_as_datetime(celda.value, libro.datemode).date().isoformat()
        if fecha != iso:
            raise ValueError('FECHA_XLS_CAMBIO')
    (col_ce, cele), (col_in, ini) = celdas[:2]
    col_fin, venc = celdas[-1]
    validar_encabezados(hoja, col_ce, col_in, col_fin)
    if (cele, ini, venc) != (r['propuesta_celebracion'], r['propuesta_inicio'], r['propuesta_vencimiento']):
        raise ValueError('PROPUESTA_DIFERENTE_DE_CELDAS')
    if dt.date.fromisoformat(ini) >= dt.date.fromisoformat(venc):
        raise ValueError('FIN_NO_POSTERIOR_AL_INICIO')
    if not re.fullmatch(r'(1103|2103)\d{7}', r['cuenta_cobol']):
        raise ValueError('CUENTA_COBOL_NO_VALIDA')
    return celdas


def planificar() -> tuple[list[dict], list[dict]]:
    if not BASE.is_dir():
        raise RuntimeError(f'No existe {BASE}')
    v7 = leer_csv(V7, {'expediente_id', 'clasificacion_v7', 'excel_origen', 'fila_excel',
                       'propuesta_celebracion', 'propuesta_inicio', 'propuesta_vencimiento',
                       'columnas_fecha_y_valores', 'cuenta_cobol', 'numero_documental',
                       'fila_excel_id', 'total_columnas'})
    v8 = leer_csv(V8, {'expediente_id', 'estado_origen_v8', 'clasificacion_v7',
                       'excel_origen', 'fila_excel', 'celebracion', 'inicio', 'fin'})
    if len(v7) != 3053 or len(v8) != 2894:
        raise RuntimeError(f'Informe incompleto o distinto al auditado: V7={len(v7)}, V8={len(v8)}')
    indice_v8 = {int(r['expediente_id']): r for r in v8}
    procesables, excluidos, libros = [], [], {}
    for r in v7:
        id_exp = int(r['expediente_id'])
        clase = r['clasificacion_v7']
        if clase not in ACEPTADOS:
            continue
        corroboracion = indice_v8.get(id_exp)
        if corroboracion is None or corroboracion['estado_origen_v8'] != 'ORIGEN_XLS_COINCIDE':
            raise RuntimeError(f'Falta confirmación V8 para exp {id_exp}')
        for a, b in [('clasificacion_v7', 'clasificacion_v7'),
                     ('excel_origen', 'excel_origen'), ('fila_excel', 'fila_excel'),
                     ('propuesta_celebracion', 'celebracion'),
                     ('propuesta_inicio', 'inicio'), ('propuesta_vencimiento', 'fin')]:
            if r[a] != corroboracion[b]:
                raise RuntimeError(f'V7/V8 no coinciden en {a} exp {id_exp}')
        try:
            fechas = origen_xls(r, libros)
            procesables.append({
                'id': id_exp, 'fila_id': int(r['fila_excel_id']),
                'excel_origen': r['excel_origen'],
                'fila_excel': int(r['fila_excel']),
                'numero': str(r['numero_documental']),
                'cuenta': r['cuenta_cobol'],
                'celebracion': r['propuesta_celebracion'],
                'inicio': r['propuesta_inicio'],
                'fin': r['propuesta_vencimiento'],
                'celdas_fecha': {str(c): v for c, v in fechas},
            })
        except (ValueError, OSError, IndexError, TypeError, xlrd.XLRDError) as exc:
            excluidos.append({'expediente_id': id_exp, 'motivo': str(exc),
                              'excel': r['excel_origen'], 'fila': r['fila_excel']})
    for libro in libros.values():
        libro.release_resources()
    if len(procesables) + len(excluidos) != 2894:
        raise RuntimeError('No se contabilizaron las 2.894 candidaturas cotejadas')
    ids = [e['id'] for e in procesables]
    if len(ids) != len(set(ids)):
        raise RuntimeError('Expedientes repetidos en plan')
    procesables.sort(key=lambda x: x['id'])
    with REPORTE.open('w', newline='', encoding='utf-8-sig') as fh:
        wr = csv.DictWriter(fh, fieldnames=['expediente_id', 'motivo', 'excel', 'fila'])
        wr.writeheader()
        wr.writerows(excluidos)
    return procesables, excluidos


def php_ejecutar(modo: str, lote: list[dict]) -> dict:
    p = subprocess.run(['docker', 'exec', '-i', 'gei-app', 'php', '-r', PHP],
                       input=json.dumps({'modo': modo, 'filas': lote}, ensure_ascii=False),
                       capture_output=True, text=True, encoding='utf-8', errors='replace')
    if p.returncode:
        raise RuntimeError((p.stderr or p.stdout or 'Error PHP')[:1600])
    salida = p.stdout.strip()
    if not salida.startswith('LOTE_OK '):
        raise RuntimeError('Respuesta inesperada desde Laravel: '+salida[:400])
    return json.loads(salida[len('LOTE_OK '):])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--aplicar', action='store_true')
    parser.add_argument('--limite', type=int, default=0)
    parser.add_argument('--verificar', action='store_true')
    args = parser.parse_args()
    if args.limite < 0:
        parser.error('--limite debe ser >= 0')
    if args.aplicar and args.verificar:
        parser.error('Seleccionar --aplicar o --verificar')
    if socket.gethostname().split('.')[0].lower() != 'gei-desarrollo':
        raise RuntimeError('Únicamente se ejecuta en gei-desarrollo')
    plan, excluidos = planificar()
    print('===== FECHAS DOCUMENTALES V9 =====', flush=True)
    print('Origen V7/V8 cotejado: 2894', flush=True)
    print('Aptos con encabezados, cuenta y XLS consistentes:', len(plan), flush=True)
    print('Excluidos para revisión de encabezados:', len(excluidos), flush=True)
    print('Reporte:', REPORTE, flush=True)
    print('No modifica COBOL, estado_vinculacion ni permisos PDF.', flush=True)
    if not args.aplicar and not args.verificar:
        print('SIMULACION_OK: sin cambios en PostgreSQL.', flush=True)
        return
    seleccion = plan[:args.limite] if args.aplicar and args.limite else plan
    acumulado = collections.Counter()
    for pos in range(0, len(seleccion), BATCH):
        lote = seleccion[pos:pos+BATCH]
        res = php_ejecutar('aplicar' if args.aplicar else 'verificar', lote)
        acumulado.update(res)
        if args.aplicar:
            print(f'{pos + len(lote)}/{len(seleccion)} LOTE_OK {json.dumps(res)}', flush=True)
    print(('FECHAS_APLICADAS' if args.aplicar else 'VERIFICACION_FECHAS'),
          json.dumps(dict(acumulado), ensure_ascii=False), flush=True)
    if args.verificar and acumulado['errores']:
        raise RuntimeError('Inconsistencias durante verificación')


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        sys.exit('ERROR: '+str(exc))
