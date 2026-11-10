#!/usr/bin/env python3
"""GeI-Desarrollo: diagnóstico V3 (solo CUENTA COBOL) SIN ESCRITURA del cruce XLS ↔ expedientes PDF.

Ejecutar: python3 /tmp/gei_diagnosticar_cruce_excel_pdf_v3.py
Salida: /tmp/gei-diagnostico-cruce-excel-pdf-v3.csv

No modifica tablas, archivos ni vínculos. No vincula clientes ni publica documentos.
Busca CUENTAS COBOL existentes en todas las columnas, sin utilizar nombres.
La coincidencia año+mes+número identifica una posible fila documental;
solo una cuenta exacta y única existente en clientes_cuentas es candidata.
NO aplica vinculaciones ni cambia estados de verificación.
"""
from __future__ import annotations

import collections
import csv
import json
import os
import re
import socket
import subprocess
import sys
import unicodedata
from pathlib import Path

PHP = r'''
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'local' ||
    (string) config('database.connections.pgsql.host') !== '192.168.50.13' ||
    \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'gei_db') {
    fwrite(STDERR, "DESTINO NO AUTORIZADO: no es GeI-Desarrollo\n"); exit(2);
}
function emitir_gei($tipo, $fila) {
    echo json_encode(['tipo' => $tipo, 'dato' => $fila], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
}
emitir_gei('CONTROL', ['ambiente' => 'local', 'base' => 'gei_db']);
\Illuminate\Support\Facades\DB::table('contratos_planillas')
    ->select('id', 'ruta_origen')->orderBy('id')->chunkById(500, function ($filas) {
        foreach ($filas as $f) emitir_gei('PLANILLA', $f);
    });
\Illuminate\Support\Facades\DB::table('contratos_planillas_filas')
    ->whereRaw('UPPER(TRIM(nombre_hoja)) = ?', ['DETALLE'])
    ->select('id','planilla_id','nombre_hoja','numero_fila','celdas')
    ->orderBy('id')->chunkById(500, function ($filas) {
        foreach ($filas as $f) emitir_gei('FILA', $f);
    });
\Illuminate\Support\Facades\DB::table('contratos_expedientes')
    ->where('estado_vinculacion','PENDIENTE')
    ->select('id','numero_documental','anio_archivo','mes_archivo',
             'ruta_carpeta_origen','ruta_relativa_normalizada')
    ->orderBy('id')->chunkById(500, function ($filas) {
        foreach ($filas as $f) emitir_gei('EXPEDIENTE', $f);
    });
\Illuminate\Support\Facades\DB::table('clientes_cuentas')
    ->where('rol','INQUILINO')->select('id','cuenta')->orderBy('id')
    ->chunkById(500, function ($filas) {
        foreach ($filas as $f) emitir_gei('CUENTA', $f);
    });
'''

MESES = {
    'ENE': 1, 'ENERO': 1, 'FEB': 2, 'FEBRERO': 2,
    'MAR': 3, 'MARZO': 3, 'ABR': 4, 'ABRIL': 4,
    'MAY': 5, 'MAYO': 5, 'JUN': 6, 'JUNIO': 6,
    'JUL': 7, 'JULIO': 7, 'AGO': 8, 'AGOSTO': 8,
    'SEP': 9, 'SEPT': 9, 'SET': 9, 'SETIEMBRE': 9, 'SEPTIEMBRE': 9,
    'OCT': 10, 'OCTUBRE': 10, 'NOV': 11, 'NOVIEMBRE': 11,
    'DIC': 12, 'DICIEMBRE': 12,
}



def simplificar(txt):
    txt = unicodedata.normalize('NFKD', str(txt or ''))
    return ''.join(c for c in txt if not unicodedata.combining(c)).upper()


def tokens(txt):
    return re.findall(r'[A-Z]+', simplificar(txt))


def periodo_xls(ruta):
    """Extrae período SOLO de nombre de XLS; usa carpeta para año como respaldo."""
    partes = Path(ruta).parts
    nombre = Path(ruta).stem
    anios = [int(v) for v in re.findall(r'(?<!\d)(20\d\d)(?!\d)', nombre)]
    if not anios:
        for carpeta in reversed(partes[:-1]):
            anios = [int(v) for v in re.findall(r'(?<!\d)(20\d\d)(?!\d)', carpeta)]
            if anios:
                break
    t = tokens(nombre)
    meses = {MESES[x] for x in t if x in MESES}
    if not meses:
        for carpeta in reversed(partes[:-1]):
            meses = {MESES[x] for x in tokens(carpeta) if x in MESES}
            if meses:
                break
    anios = set(anios)
    return (next(iter(anios)) if len(anios) == 1 else None,
            next(iter(meses)) if len(meses) == 1 else None)


def numero(valor):
    if isinstance(valor, bool) or valor is None:
        return None
    if isinstance(valor, (int, float)):
        if valor <= 0 or valor > 99999999 or int(valor) != valor:
            return None
        return str(int(valor))
    s = str(valor).strip()
    if re.fullmatch(r'\d{1,8}(?:\.0+)?', s):
        return str(int(float(s)))
    return None


def leer_celdas(raw):
    if isinstance(raw, str):
        raw = json.loads(raw)
    if not isinstance(raw, list):
        raise ValueError('celdas JSON no es una lista')
    return [i.get('valor') if isinstance(i, dict) else None for i in raw]


def cuentas_candidatas(valores, cuentas):
    """Detecta cuentas de inquilino en CUALQUIER columna sin adivinar el esquema.

    Sólo se aceptan cuentas existentes en clientes_cuentas. Una columna
    coincidente no es por sí sola prueba de identidad ni de titularidad.
    """
    candidatos = set()
    evidencias = []
    for posicion, v in enumerate(valores, 1):
        s = str(v or '').strip()
        if not s:
            continue
        cuentas_celda = []
        # Formato abreviado habitual 23906/03 (7 dígitos de sufijo).
        m = re.fullmatch(r'(\d{5})\s*[/\-]\s*(\d{2})', s)
        if m:
            sufijo = ''.join(m.groups())
            cuentas_celda = [p+sufijo for p in ('1103','2103')
                             if p+sufijo in cuentas]
        else:
            # Cuenta completa de 11 dígitos (también si xlrd la obtuvo numérica).
            # 'numero' limita a 8 cifras (número de contrato); una cuenta tiene 11.
            n = None
            if isinstance(v, (int, float)) and not isinstance(v, bool):
                if v > 0 and int(v) == v:
                    n = str(int(v))
            elif re.fullmatch(r'\d{11}', s):
                n = s
            if n and len(n) == 11 and n[:4] in ('1103','2103') and n in cuentas:
                cuentas_celda = [n]
            # Formato completo 1103-23906-03.
            m2 = re.fullmatch(r'(1103|2103)\s*[-/ ]\s*(\d{5})\s*[-/ ]\s*(\d{2})', s)
            if m2 and ''.join(m2.groups()) in cuentas:
                cuentas_celda = [''.join(m2.groups())]
        for c in cuentas_celda:
            candidatos.add(c)
            evidencias.append(f'{posicion}:{s}=>{c}')
    return sorted(candidatos), evidencias


def obtener():
    comando = ['docker', 'exec', 'gei-app', 'php', '-r', PHP]
    proc = subprocess.Popen(comando, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            text=True, encoding='utf-8', errors='replace', bufsize=1)
    planillas, filas, exps, cuentas = {}, [], [], {}
    control = False
    try:
        assert proc.stdout is not None
        for linea in proc.stdout:
            if not linea.strip():
                continue
            try:
                obj = json.loads(linea)
                tipo, dato = obj['tipo'], obj['dato']
            except (ValueError, KeyError) as e:
                raise RuntimeError('Respuesta inesperada de PHP: ' + linea[:200]) from e
            if tipo == 'CONTROL':
                control = True
            elif tipo == 'PLANILLA':
                planillas[int(dato['id'])] = dato['ruta_origen']
            elif tipo == 'FILA':
                filas.append(dato)
            elif tipo == 'EXPEDIENTE':
                exps.append(dato)
            elif tipo == 'CUENTA':
                cuentas[str(dato['cuenta'])] = int(dato['id'])
        rc = proc.wait()
        err = proc.stderr.read() if proc.stderr else ''
        if rc or not control:
            raise RuntimeError(f'PHP devolvió {rc}; {err[:1500]}')
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait()
    return planillas, filas, exps, cuentas


def main():
    if socket.gethostname().split('.')[0].lower() != 'gei-desarrollo':
        sys.exit('ERROR: ejecutar únicamente en gei-desarrollo')
    planillas, filas, exps, cuentas = obtener()
    indice = collections.defaultdict(list)
    formatos = collections.Counter()
    sin_periodo = set()
    filas_elegibles = 0
    filas_no_numericas = 0
    indice_numero_anio = collections.defaultdict(list)
    cuentas_en_filas = 0
    columnas_cuenta = collections.Counter()
    for f in filas:
        plan = planillas.get(int(f['planilla_id']))
        if plan is None:
            raise RuntimeError('Falta planilla en índice: '+str(f['planilla_id']))
        valores = leer_celdas(f['celdas'])
        formatos[(len(valores), str(Path(plan).parts[1] if len(Path(plan).parts) > 1 else '?'))] += 1
        n = numero(valores[0]) if valores else None
        if n is None:
            filas_no_numericas += 1
            continue
        anio, mes = periodo_xls(plan)
        if not anio or not mes:
            sin_periodo.add(plan)
            continue
        filas_elegibles += 1
        cs, evidencia_cuentas = cuentas_candidatas(valores, cuentas)
        if cs:
            cuentas_en_filas += 1
            for ev in evidencia_cuentas:
                columnas_cuenta[(str(anio), len(valores), ev.split(':', 1)[0])] += 1
        registro = {
            'fila_id': int(f['id']), 'planilla': plan, 'hoja': f['nombre_hoja'],
            'numero_fila': f['numero_fila'], 'valores': valores,
            'cuentas': cs, 'evidencias_cuenta': evidencia_cuentas,
        }
        indice[(anio, mes, n)].append(registro)
        indice_numero_anio[(anio, n)].append((mes, int(f['id'])))
    exps_por_clave = collections.Counter()
    for e in exps:
        n = numero(e.get('numero_documental'))
        a, m = e.get('anio_archivo'), e.get('mes_archivo')
        if a and m and n:
            exps_por_clave[(int(a), int(m), n)] += 1

    estados = collections.Counter()
    ejemplos = collections.defaultdict(list)
    renglones = []
    for e in exps:
        a, m = e.get('anio_archivo'), e.get('mes_archivo')
        n = numero(e.get('numero_documental'))
        k = (int(a), int(m), n) if a and m and n else None
        candidatos = indice.get(k, []) if k else []
        cantidad = len(candidatos)
        fila = candidatos[0] if cantidad == 1 else None
        cuenta = ''
        if k is None:
            estado = 'EXPEDIENTE_SIN_PERIODO_O_NUMERO'
        elif cantidad == 0:
            estado = 'SIN_FILA_DETALLE_MISMO_PERIODO_NUMERO'
        elif cantidad > 1 or exps_por_clave[k] > 1:
            estado = 'AMBIGUO_MULTIPLES_FILAS_O_EXPEDIENTES'
        elif fila is not None:
            cs = fila['cuentas']
            if len(cs) == 1:
                estado = 'CANDIDATO_CUENTA_COBOL_UNICA'  # Aún NO VALIDADO
                cuenta = cs[0]
            elif len(cs) > 1:
                estado = 'AMBIGUO_MULTIPLES_CUENTAS_COBOL'
            else:
                estado = 'SIN_CUENTA_COBOL_VERIFICABLE'
        else:
            raise AssertionError('Estado inesperado')
        estados[estado] += 1
        if len(ejemplos[estado]) < 3:
            ejemplos[estado].append((e['id'], n, a, m))
        otros_meses = []
        if a and n and m:
            otros_meses = sorted({mes_xls for mes_xls, _ in indice_numero_anio.get((int(a), n), []) if mes_xls != int(m)})
        renglones.append({
            'expediente_id': e['id'], 'estado_diagnostico': estado,
            'anio_pdf': a or '', 'mes_pdf': m or '', 'numero_pdf': n or '',
            'coincidencias_xls': cantidad,
            'fila_xls_id': fila['fila_id'] if fila else '',
            'planilla': fila['planilla'] if fila else '',
            'numero_fila': fila['numero_fila'] if fila else '',
            'evidencia_cuenta_xls': '|'.join(fila['evidencias_cuenta']) if fila else '',
            'cuenta_cobol_candidata': cuenta,
            'otros_meses_mismo_numero_anio': ','.join(map(str, otros_meses)),
            'ruta_carpeta_origen_pdf': e.get('ruta_carpeta_origen') or '',
        })
    destino = Path('/tmp/gei-diagnostico-cruce-excel-pdf-v3.csv')
    descriptor = os.open(str(destino), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, 'w', newline='', encoding='utf-8') as fd:
        campos = list(renglones[0].keys()) if renglones else [
            'expediente_id', 'estado_diagnostico']
        w = csv.DictWriter(fd, fieldnames=campos)
        w.writeheader()
        w.writerows(renglones)
    destino.chmod(0o600)
    print('===== DIAGNÓSTICO SIN CAMBIOS =====')
    print('Planillas:', len(planillas))
    print('Filas DETALLE examinadas:', len(filas))
    print('Filas con número y período legibles:', filas_elegibles)
    print('Filas sin número inicial:', filas_no_numericas)
    print('Planillas sin período identificable:', len(sin_periodo))
    print('Cuentas INQUILINO disponibles:', len(cuentas))
    print('Expedientes PENDIENTE examinados:', len(exps))
    print('Filas DETALLE con cuenta existente localizada en alguna columna:', cuentas_en_filas)
    print('CRITERIO DE IDENTIDAD: CUENTA COBOL EXCLUSIVAMENTE; NOMBRES IGNORADOS')
    print('===== RESULTADOS =====')
    for estado, cantidad in sorted(estados.items()):
        print(f'{estado}: {cantidad}; ejemplos (id, número, año, mes): {ejemplos[estado]}')
    print('===== FORMATO DETALLE (10 variantes más comunes; columnas, carpeta) =====')
    for (cols, yr), cantidad in formatos.most_common(10):
        print(f'{cols} columnas | {yr}: {cantidad} filas')
    print('===== UBICACION DE CUENTAS (año, columnas de fila, columna del valor; top 20) =====')
    for clave, cantidad in columnas_cuenta.most_common(20):
        print(*clave, cantidad, sep=' | ')
    print('REPORTE:', destino)
    print('SIN_ESCRITURAS_EN_POSTGRESQL; SIN_MOVIMIENTO_DE_ARCHIVOS')
    print('ATENCIÓN: una cuenta existente es una candidatura, NO una vinculación confirmada.')
    print('No se utilizaron nombres ni sus similitudes para aceptar o descartar cuentas.')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, OSError, subprocess.SubprocessError) as exc:
        sys.exit('ERROR: ' + str(exc))
