#!/usr/bin/env python3
"""GeI-Desarrollo: reclasificación conservadora de propuestas de fechas V6.

NO modifica PostgreSQL ni documentos. NO autoriza PDFs.
No se utilizan nombres, CUIT o domicilios.

Analiza aparte contratos firmados después de la fecha de inicio, lo que
NO es necesariamente una inconsistencia. El límite de 90 días es un
umbral de diagnóstico, NO una regla jurídica ni de aprobación automática.

Uso:
  python3 /tmp/gei_revisar_fechas_firma_posterior_v7.py
  python3 /tmp/gei_revisar_fechas_firma_posterior_v7.py --max-dias 60

Entrada: /tmp/gei-diagnostico-fechas-documentales-v6.csv
Salida:  /tmp/gei-diagnostico-fechas-documentales-v7.csv
"""
from __future__ import annotations

import argparse
import collections
import csv
import datetime as dt
import socket
import sys
from pathlib import Path

ENTRADA = Path('/tmp/gei-diagnostico-fechas-documentales-v6.csv')
SALIDA = Path('/tmp/gei-diagnostico-fechas-documentales-v7.csv')
IGNORAR_SOLO_PARA_FECHAS = {'SIN_PDF_CONTRATO', 'VARIOS_PDF_CONTRATO'}
BANDERAS_FIRMA_POSTERIOR = {'FECHAS_FUERA_DE_ORDEN', 'INTERVALO_CELEBRACION_INICIO_ATIPICO'}


def fecha(s: str) -> dt.date | None:
    if not s:
        return None
    try:
        return dt.date.fromisoformat(s)
    except ValueError:
        return None


def clasificar(r: dict[str, str], max_dias: int) -> dict[str, str]:
    flags = set(x.strip() for x in (r.get('observaciones') or '').split(' | ') if x.strip())
    errores_fechas = flags - IGNORAR_SOLO_PARA_FECHAS
    cele = fecha(r.get('propuesta_celebracion', ''))
    ini = fecha(r.get('propuesta_inicio', ''))
    fin = fecha(r.get('propuesta_vencimiento', ''))
    posterior_dias = (cele - ini).days if cele and ini else None
    if r.get('clasificacion') == 'CANDIDATO_FECHAS' and not errores_fechas:
        tipo = 'PROPUESTA_ESTANDAR'
    elif (cele and ini and fin and ini < fin and
          posterior_dias is not None and 1 <= posterior_dias <= max_dias and
          errores_fechas == BANDERAS_FIRMA_POSTERIOR):
        tipo = 'PROPUESTA_FIRMA_POSTERIOR'
    else:
        tipo = 'REVISION_MANUAL'
    pdf_cantidad = int(r.get('pdf_contrato_cantidad') or 0)
    if tipo != 'REVISION_MANUAL' and pdf_cantidad != 1:
        # Mantener la propuesta temporal, pero separarla de expedientes con PDF único.
        tipo = 'PROPUESTA_SIN_PDF_PRINCIPAL_UNICO'
    salida = dict(r)
    salida['dias_firma_posterior_al_inicio'] = str(posterior_dias if posterior_dias is not None else '')
    salida['clasificacion_v7'] = tipo
    salida['vigencia_confirmada'] = 'NO'
    salida['documento_autorizado'] = 'NO'
    return salida


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--max-dias', type=int, default=90,
                    help='Umbral de análisis de firmas posteriores (1 a 365 días; predeterminado 90)')
    args = ap.parse_args()
    if not 1 <= args.max_dias <= 365:
        ap.error('--max-dias debe estar entre 1 y 365')
    if socket.gethostname().split('.')[0].lower() != 'gei-desarrollo':
        raise RuntimeError('Este diagnóstico está limitado al servidor gei-desarrollo')
    if not ENTRADA.is_file():
        raise RuntimeError(f'No existe {ENTRADA}; ejecutar antes el diagnóstico V6')
    with ENTRADA.open('r', encoding='utf-8-sig', newline='') as f:
        rd = csv.DictReader(f)
        requeridas = {'expediente_id', 'clasificacion', 'observaciones',
                      'propuesta_celebracion', 'propuesta_inicio',
                      'propuesta_vencimiento', 'pdf_contrato_cantidad'}
        if not rd.fieldnames or not requeridas.issubset(rd.fieldnames):
            raise RuntimeError('El CSV no corresponde al diagnóstico V6 esperado')
        filas = [clasificar(r, args.max_dias) for r in rd]
    if not filas:
        raise RuntimeError('No se hallaron filas para clasificar')
    ids = [r['expediente_id'] for r in filas]
    if len(ids) != len(set(ids)):
        raise RuntimeError('El CSV tiene expedientes duplicados')
    # No asumir que otros servidores ni bases tienen exactamente 3.053.
    with SALIDA.open('w', encoding='utf-8-sig', newline='') as f:
        wr = csv.DictWriter(f, fieldnames=list(filas[0]))
        wr.writeheader()
        wr.writerows(filas)

    counts = collections.Counter(r['clasificacion_v7'] for r in filas)
    por_anio = collections.defaultdict(collections.Counter)
    gaps = []
    for r in filas:
        por_anio[r['anio_archivo']][r['clasificacion_v7']] += 1
        if r['clasificacion_v7'] == 'PROPUESTA_FIRMA_POSTERIOR':
            gaps.append(int(r['dias_firma_posterior_al_inicio']))
    print('===== DIAGNÓSTICO V7 — FIRMA POSTERIOR AL INICIO =====')
    print('Expedientes:', len(filas))
    print('Máximo de días para hipótesis de firma posterior:', args.max_dias)
    for clase, n in counts.most_common():
        print(f'{clase}: {n}')
    print('===== POR AÑO =====')
    for anio, counter in sorted(por_anio.items()):
        print(anio, dict(sorted(counter.items())))
    if gaps:
        print('===== DEMORA DE FIRMA (DÍAS; SOLO PROPUESTAS) =====')
        print('Mínimo:', min(gaps), 'Máximo:', max(gaps))
        print('Hasta 7 días:', sum(x <= 7 for x in gaps))
        print('8 a 30 días:', sum(8 <= x <= 30 for x in gaps))
        print('31 a 60 días:', sum(31 <= x <= 60 for x in gaps))
        print('61 a 90 días:', sum(61 <= x <= 90 for x in gaps))
        print('91 a 365 días:', sum(91 <= x <= 365 for x in gaps))
    for clase in ['PROPUESTA_FIRMA_POSTERIOR', 'REVISION_MANUAL',
                  'PROPUESTA_SIN_PDF_PRINCIPAL_UNICO']:
        muestra = [r for r in filas if r['clasificacion_v7'] == clase][:5]
        if muestra:
            print('===== EJEMPLOS:', clase, '=====')
            for r in muestra:
                print('exp', r['expediente_id'], 'cuenta', r['cuenta_cobol'],
                      'celebración', r['propuesta_celebracion'],
                      'inicio', r['propuesta_inicio'],
                      'fin', r['propuesta_vencimiento'],
                      'motivos', r['observaciones'])
    print('CSV:', SALIDA)
    print('SOLO_LECTURA_OK: no se modificaron expedientes, fechas, PDFs ni permisos.')
    print('Todas las propuestas siguen sujetas a verificar el significado de columnas.')


if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        sys.exit(f'ERROR: {e}')
