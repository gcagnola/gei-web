#!/usr/bin/env python3
"""GeI-Desarrollo: contrastar las 2.894 propuestas de vigencia V7 con los XLS reales.

SOLO LECTURA. No vincula clientes, no cambia fechas, expedientes, archivos,
permisos ni documentos. No utiliza nombres, CUIT ni domicilios.

Uso:
    python3 /tmp/gei_verificar_origen_fechas_v8.py
    python3 /tmp/gei_verificar_origen_fechas_v8.py --variantes 2

Valida las fechas propuestas contra las celdas originales xlrd y muestra los
rótulos cercanos a esas columnas en ejemplos por año/formato para comprobar
antes de aplicar que 'celebración', 'inicio' y 'vencimiento' tengan ese sentido.
"""
from __future__ import annotations

import argparse
import collections
import csv
import datetime as dt
import re
import socket
import sys
from pathlib import Path

import xlrd

BASE = Path.home() / 'dockers/samba-server/privados/contratos'
ORIGEN = Path('/tmp/gei-diagnostico-fechas-documentales-v7.csv')
SALIDA = Path('/tmp/gei-verificacion-origen-fechas-v8.csv')
ACEPTABLES = {'PROPUESTA_ESTANDAR', 'PROPUESTA_FIRMA_POSTERIOR'}


def leer_fecha(celda: xlrd.sheet.Cell, datemode: int) -> str | None:
    if celda.ctype != xlrd.XL_CELL_DATE:
        return None
    try:
        return xlrd.xldate_as_datetime(celda.value, datemode).date().isoformat()
    except (ValueError, OverflowError, TypeError):
        return None


def posiciones(s: str) -> list[tuple[int, str]]:
    r = []
    for item in s.split(' | '):
        item = item.strip()
        if not item:
            continue
        m = re.fullmatch(r'(\d+):(\d{4}-\d{2}-\d{2})', item)
        if m is None:
            raise ValueError(f'Formato de celda de fecha inesperado: {item!r}')
        r.append((int(m.group(1)), m.group(2)))
    return r


def fecha_en_original(hoja, datemode, fila_excel: int, col: int):
    if not 1 <= fila_excel <= hoja.nrows or not 1 <= col <= hoja.ncols:
        return None
    return leer_fecha(hoja.cell(fila_excel - 1, col - 1), datemode)


def resumen_rotulos(hoja, columnas):
    out = []
    for col in columnas:
        if col < 1 or col > hoja.ncols:
            out.append(f'c{col}: FUERA DE RANGO')
            continue
        candidatos = []
        for fila in range(min(5, hoja.nrows)):
            celda = hoja.cell(fila, col - 1)
            if celda.ctype == xlrd.XL_CELL_TEXT and str(celda.value).strip():
                dato = re.sub(r'\s+', ' ', str(celda.value).strip())[:42]
                candidatos.append(f'F{fila+1}={dato!r}')
        out.append(f'c{col}: '+(' / '.join(candidatos[:3]) if candidatos else 'SIN ROTULO'))
    return out


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--variantes', type=int, default=2, help='Máximo de formatos de fecha por año para mostrar')
    args = p.parse_args()
    if not 1 <= args.variantes <= 6:
        p.error('--variantes debe estar entre 1 y 6')
    if socket.gethostname().split('.')[0].lower() != 'gei-desarrollo':
        raise RuntimeError('Solo en gei-desarrollo')
    if not ORIGEN.is_file():
        raise RuntimeError(f'No existe {ORIGEN}; ejecutar antes V7')
    if not BASE.is_dir():
        raise RuntimeError(f'No existe el directorio XLS: {BASE}')

    with ORIGEN.open('r', encoding='utf-8-sig', newline='') as f:
        rd = csv.DictReader(f)
        required = {'expediente_id', 'clasificacion_v7', 'excel_origen', 'fila_excel',
                    'propuesta_celebracion', 'propuesta_inicio', 'propuesta_vencimiento',
                    'columnas_fecha_y_valores', 'anio_archivo', 'total_columnas'}
        if not rd.fieldnames or not required.issubset(rd.fieldnames):
            raise RuntimeError('El CSV V7 no tiene las columnas esperadas')
        datos = list(rd)
    ids = [r['expediente_id'] for r in datos]
    if len(ids) != len(set(ids)):
        raise RuntimeError('Expedientes repetidos en V7')

    candidatos = [r for r in datos if r['clasificacion_v7'] in ACEPTABLES]
    resultados = []
    archivos_cache = {}
    formatos = collections.Counter()
    ejemplos = {}
    fallas = collections.Counter()
    motivos_fecha = collections.Counter()
    for r in candidatos:
        clave = r['expediente_id']
        ruta_rel = Path(r['excel_origen'])
        try:
            if ruta_rel.is_absolute() or '..' in ruta_rel.parts:
                raise ValueError('RUTA_EXCEL_FUERA_DE_BASE')
            ruta = (BASE / ruta_rel).resolve()
            if not ruta.is_relative_to(BASE.resolve()) or not ruta.is_file():
                raise ValueError('XLS_NO_ENCONTRADO')
            if str(ruta) not in archivos_cache:
                archivos_cache[str(ruta)] = xlrd.open_workbook(str(ruta), on_demand=True)
            libro = archivos_cache[str(ruta)]
            hojas_detalle = [h for h in libro.sheet_names() if h.strip().upper() == 'DETALLE']
            if len(hojas_detalle) != 1:
                raise ValueError('HOJA_DETALLE_NO_UNICA')
            hoja = libro.sheet_by_name(hojas_detalle[0])
            fila = int(r['fila_excel'])
            cols = posiciones(r['columnas_fecha_y_valores'])
            if len(cols) not in (3, 4):
                raise ValueError('CANTIDAD_DE_FECHAS_NO_STANDARD')
            for col, val in cols:
                cel = fecha_en_original(hoja, libro.datemode, fila, col)
                if cel != val:
                    raise ValueError(f'ORIGEN_NO_COINCIDE_C{col}_{cel or "VACIO"}_VS_{val}')
            for campo, esperado in [('propuesta_celebracion', cols[0][1]),
                                    ('propuesta_inicio', cols[1][1]),
                                    ('propuesta_vencimiento', cols[-1][1])]:
                if r[campo] != esperado:
                    raise ValueError('PROPUESTA_NO_COINCIDE_'+campo.upper())
            if int(r['total_columnas']) != hoja.ncols:
                # Podría ser una fila con celdas finales vacías omitidas en la planilla; revisar.
                raise ValueError('CANTIDAD_COLUMNAS_XLS_DISTINTA')
            estado = 'ORIGEN_XLS_COINCIDE'
            grupo = (r['anio_archivo'], int(r['total_columnas']),
                     ','.join(str(c) for c, _ in cols))
            formatos[grupo] += 1
            ejemplos.setdefault(grupo, (r, hoja))
            delta = (dt.date.fromisoformat(r['propuesta_inicio']) -
                     dt.date.fromisoformat(r['propuesta_celebracion'])).days
            if delta > 365:
                motivos_fecha['CELEBRACION_MAS_DE_UN_ANIO_ANTES_DE_INICIO'] += 1
            if r['clasificacion_v7'] == 'PROPUESTA_FIRMA_POSTERIOR':
                motivos_fecha['FIRMA_POSTERIOR'] += 1
        except (OSError, ValueError, IndexError, xlrd.XLRDError) as exc:
            estado = 'REVISAR_ORIGEN: ' + str(exc)
            fallas[str(exc).split(':', 1)[0]] += 1
        resultados.append({
            'expediente_id': clave,
            'clasificacion_v7': r['clasificacion_v7'],
            'estado_origen_v8': estado,
            'anio_archivo': r['anio_archivo'],
            'excel_origen': r['excel_origen'],
            'fila_excel': r['fila_excel'],
            'celebracion': r['propuesta_celebracion'],
            'inicio': r['propuesta_inicio'],
            'fin': r['propuesta_vencimiento'],
            'posiciones_fecha': r['columnas_fecha_y_valores'],
        })

    with SALIDA.open('w', encoding='utf-8-sig', newline='') as f:
        campos = list(resultados[0]) if resultados else [
            'expediente_id', 'clasificacion_v7', 'estado_origen_v8', 'anio_archivo',
            'excel_origen', 'fila_excel', 'celebracion', 'inicio', 'fin', 'posiciones_fecha']
        wr = csv.DictWriter(f, fieldnames=campos)
        wr.writeheader()
        wr.writerows(resultados)
    cuenta_ok = sum(r['estado_origen_v8'] == 'ORIGEN_XLS_COINCIDE' for r in resultados)
    print('===== VERIFICACIÓN DE ORIGEN XLS V8 — SOLO LECTURA =====')
    print('Expedientes V7 totales:', len(datos))
    print('Propuestas de fecha examinadas:', len(candidatos))
    print('Fechas cotejadas con XLS sin diferencias:', cuenta_ok)
    print('Para revisar por diferencias de origen:', len(candidatos) - cuenta_ok)
    print('No incluidos: revisión manual / PDF no único:', len(datos) - len(candidatos))
    if fallas:
        print('===== DESAJUSTES =====')
        for motivo, n in fallas.most_common(15):
            print(n, motivo)
    print('===== ALERTAS ADICIONALES =====')
    for m, n in motivos_fecha.most_common():
        print(m, n)
    print('===== PATRONES MÁS FRECUENTES Y RÓTULOS DE SUS PLANILLAS =====')
    por_anio = collections.defaultdict(list)
    for grupo, n in formatos.items():
        por_anio[grupo[0]].append((n, grupo))
    for anio, grupos in sorted(por_anio.items()):
        for n, grupo in sorted(grupos, reverse=True)[:args.variantes]:
            r, hoja = ejemplos[grupo]
            cols = [int(x) for x in grupo[2].split(',')]
            print(f'ANIO={anio} COLUMNA_TOTAL={grupo[1]} FECHAS={grupo[2]} CASOS={n}')
            print('  EJEMPLO: expediente', r['expediente_id'], 'XLS', r['excel_origen'],
                  'fila', r['fila_excel'])
            for etiqueta in resumen_rotulos(hoja, cols):
                print(' ', etiqueta)
            print('  FECHAS:', r['columnas_fecha_y_valores'])
    print('REPORTE:', SALIDA)
    print('SIN_ESCRITURAS_EN_POSTGRESQL; SIN_MOVIMIENTO_DE_ARCHIVOS; SIN_HABILITAR_PDF')
    print('La coincidencia de celdas NO prueba por sí misma el significado contractual de las columnas.')


if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        sys.exit('ERROR: '+str(e))
