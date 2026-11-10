#!/usr/bin/env python3
"""GeI-Desarrollo: proponer vigencias XLS para los 3.053 expedientes vinculados.

USO: python3 /tmp/gei_diagnosticar_fechas_documentales_v6.py

Solo lectura. Requiere gei_auditar_expedientes_vinculados_v5.py en /tmp.
No usa nombres para vincular. No cambia fechas, estados, PDF ni archivos.

El patrón de columnas es una HIPÓTESIS: fecha 1=celebración,
fecha 2=inicio y última fecha=vencimiento. Solo emite candidatos
cuando la secuencia pasa controles estrictos. No habilitar automáticamente
expedientes con este reporte; revisar modelos de Excel antes de aplicar.
"""
from __future__ import annotations

import collections
import csv
import datetime as dt
import importlib.util
import json
import re
import socket
import subprocess
import sys
from pathlib import Path

V5 = Path(__file__).with_name('gei_auditar_expedientes_vinculados_v5.py')
SALIDA = Path('/tmp/gei-diagnostico-fechas-documentales-v6.csv')


def cargar_v5():
    if not V5.is_file():
        raise RuntimeError(f'Falta la auditoría V5: {V5}')
    spec = importlib.util.spec_from_file_location('gei_auditoria_v5', V5)
    if spec is None or spec.loader is None:
        raise RuntimeError('No se pudo importar la auditoría V5')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def obtener(mod):
    p = subprocess.run(['docker', 'exec', 'gei-app', 'php', '-r', mod.PHP],
                       capture_output=True, text=True, encoding='utf-8', errors='replace')
    if p.returncode:
        raise RuntimeError(f'Consulta PostgreSQL falló: {(p.stderr or p.stdout)[:1000]}')
    esperado = None
    resultados = []
    for linea in p.stdout.splitlines():
        if not linea.strip():
            continue
        d = json.loads(linea)
        if d.get('tipo') == 'CONTROL':
            esperado = int(d['total'])
        elif d.get('tipo') == 'EXP':
            resultados.append(d['dato'])
        else:
            raise RuntimeError('Respuesta inesperada desde PostgreSQL')
    if esperado != len(resultados) or esperado is None:
        raise RuntimeError(f'Lectura parcial: recibidos {len(resultados)}; esperados {esperado}')
    ids = [int(e['id']) for e in resultados]
    if len(ids) != len(set(ids)):
        raise RuntimeError('Expedientes duplicados en la consulta')
    return resultados


def parsear_fecha(celda):
    if not isinstance(celda, dict) or int(celda.get('tipo_xlrd') or 0) != 3:
        return None
    dato = celda.get('valor')
    if not isinstance(dato, str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', dato):
        return None
    try:
        fecha = dt.date.fromisoformat(dato)
    except ValueError:
        return None
    if fecha.year < 2015 or fecha.year > 2045:
        return None
    return fecha


def fecha_sql(valor):
    return str(valor)[:10] if valor is not None else ''


def analizar(e):
    try:
        celdas = json.loads(e['celdas']) if isinstance(e['celdas'], str) else e['celdas']
    except (ValueError, TypeError):
        celdas = None
    docs = e.get('documentos') or []
    t_contrato = sum(str(d.get('tipo')) == 'CONTRATO' for d in docs)
    errores = []
    if not isinstance(celdas, list) or not celdas:
        celdas = []
        errores.append('CELDAS_INVALIDAS')
    if e.get('rol_maestro') != 'INQUILINO' or e.get('cuenta_maestra') != e.get('cuenta_inquilino'):
        errores.append('CUENTA_INCOMPATIBLE')
    if str(e.get('nombre_hoja', '')).strip().upper() != 'DETALLE':
        errores.append('FILA_NO_DETALLE')
    fechas = []
    for i, celda in enumerate(celdas, 1):
        if not isinstance(celda, dict):
            continue
        f = parsear_fecha(celda)
        if f is not None:
            fechas.append((i, f))
    fechas_str = ' | '.join(f'{pos}:{f.isoformat()}' for pos, f in fechas)
    if len(fechas) not in (3, 4):
        errores.append('FECHAS_CANTIDAD_NO_ESTANDAR')
    propuesta = None
    if len(fechas) in (3, 4):
        (pos_cele, cele), (pos_ini, inicio) = fechas[:2]
        pos_fin, fin = fechas[-1]
        n = len(celdas)
        if pos_cele not in (7, 8, 9) or pos_ini != pos_cele + 1:
            errores.append('COLUMNAS_INICIO_NO_ESTANDAR')
        if pos_fin <= pos_ini + 1 or n - pos_fin < 0 or n - pos_fin > 4:
            errores.append('COLUMNA_FIN_NO_ESTANDAR')
        if not (cele <= inicio < fin):
            errores.append('FECHAS_FUERA_DE_ORDEN')
        if not (0 <= (inicio - cele).days <= 730):
            errores.append('INTERVALO_CELEBRACION_INICIO_ATIPICO')
        if not (90 <= (fin - inicio).days <= 3653):
            errores.append('PLAZO_ATIPICO')
        anio = int(e.get('anio_archivo') or 0)
        if abs(cele.year - anio) > 1:
            errores.append('ANIO_CELEBRACION_DIFIERE_ARCHIVO')
        if len(fechas) == 4:
            pos_extra, f_extra = fechas[2]
            if not (pos_ini < pos_extra < pos_fin):
                errores.append('FECHA_EXTRA_DESUBICADA')
        propuesta = (cele, inicio, fin)
    for campo, ix in (('fecha_celebracion', 0), ('fecha_inicio', 1), ('fecha_fin', 2)):
        previo = fecha_sql(e.get(campo))
        if propuesta and previo and previo != propuesta[ix].isoformat():
            errores.append('FECHA_BD_CONFLICTIVA')
    if t_contrato == 0:
        errores.append('SIN_PDF_CONTRATO')
    elif t_contrato > 1:
        errores.append('VARIOS_PDF_CONTRATO')
    # Conservamos la distinción entre una posible vigencia y permiso para abrir PDF:
    # los dos flags de PDF son informativos; el modelo puede ser candidato
    # de fechas sin que el expediente/documentos sean publicables.
    errores_fechas = [x for x in errores if x not in ('SIN_PDF_CONTRATO','VARIOS_PDF_CONTRATO')]
    categoria = 'CANDIDATO_FECHAS' if not errores_fechas else 'REVISION_FECHAS'
    if propuesta:
        celebracion, inicio, vencimiento = [x.isoformat() for x in propuesta]
    else:
        celebracion = inicio = vencimiento = ''
    return {
        'expediente_id': e['id'],
        'numero_documental': e.get('numero_documental'),
        'anio_archivo': e.get('anio_archivo'),
        'mes_archivo': e.get('mes_archivo'),
        'cuenta_cobol': e.get('cuenta_inquilino'),
        'fila_excel_id': e.get('planilla_fila_id'),
        'excel_origen': e.get('planilla_ruta'),
        'fila_excel': e.get('numero_fila'),
        'total_columnas': len(celdas),
        'columnas_fecha_y_valores': fechas_str,
        'propuesta_celebracion': celebracion,
        'propuesta_inicio': inicio,
        'propuesta_vencimiento': vencimiento,
        'pdf_contrato_cantidad': t_contrato,
        'clasificacion': categoria,
        'observaciones': ' | '.join(errores),
    }


def main():
    if socket.gethostname().split('.')[0].lower() != 'gei-desarrollo':
        raise RuntimeError('Únicamente ejecutable en gei-desarrollo')
    mod = cargar_v5()
    datos = obtener(mod)
    filas = [analizar(e) for e in datos]
    columnas = list(filas[0]) if filas else []
    with SALIDA.open('w', encoding='utf-8-sig', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=columnas)
        writer.writeheader()
        writer.writerows(filas)
    categorias = collections.Counter(f['clasificacion'] for f in filas)
    problemas = collections.Counter()
    formatos = collections.Counter()
    for f in filas:
        for x in filter(None, f['observaciones'].split(' | ')):
            problemas[x] += 1
        posiciones = tuple(part.split(':', 1)[0] for part in f['columnas_fecha_y_valores'].split(' | ') if part)
        formatos[(f['anio_archivo'], f['total_columnas'], ','.join(posiciones))] += 1
    print('===== DIAGNÓSTICO V6 — PROPUESTAS DE FECHAS (SOLO LECTURA) =====')
    print('Expedientes examinados:', len(filas))
    print('Por clasificación:', dict(categorias))
    print('Expedientes sin PDF principal:', problemas.get('SIN_PDF_CONTRATO', 0))
    print('Expedientes con varios PDF principales:', problemas.get('VARIOS_PDF_CONTRATO', 0))
    print('===== MOTIVOS DE REVISIÓN =====')
    for nombre, n in problemas.most_common():
        print(f'{nombre}: {n}')
    print('===== PATRONES DE FECHAS: año | columnas | posiciones | filas =====')
    for (anio, ncols, posiciones), n in formatos.most_common(30):
        print(f'{anio} | {ncols} | {posiciones or "NINGUNA"} | {n}')
    print('===== EJEMPLOS PARA REVISIÓN =====')
    for f in (f for f in filas if f['clasificacion'] == 'REVISION_FECHAS'):
        print(f"exp {f['expediente_id']}, XLS {f['fila_excel_id']}, fechas {f['columnas_fecha_y_valores']}, motivos {f['observaciones']}")
        if not hasattr(main, 'muestra'):
            main.muestra = 0
        main.muestra += 1
        if main.muestra >= 10:
            break
    print('CSV:', SALIDA)
    print('SOLO_LECTURA_OK: ninguna fecha, permiso, expediente o archivo fue modificado.')
    print('Las fechas candidatas son PROPUESTAS, no vigencias verificadas ni autorización de PDF.')


if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        sys.exit('ERROR: ' + str(e))
