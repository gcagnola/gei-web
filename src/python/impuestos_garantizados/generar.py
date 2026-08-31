from __future__ import annotations

import argparse
import csv
import json
import re
import sys
import unicodedata
from decimal import Decimal
from pathlib import Path

HERE = Path(__file__).resolve().parent
if str(HERE) not in sys.path:
    sys.path.insert(0, str(HERE))

from dailoc_parser import parsear_dailoc  # noqa: E402
from dailoc_pdf import generar_pdf_dailoc  # noqa: E402


def _diferencia(a: Decimal, b: Decimal) -> Decimal:
    return (a - b).copy_abs()


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description='Analiza o genera el detalle PDF de impuestos garantizados (DAILOC).'
    )
    parser.add_argument('accion', choices=('analizar', 'generar'))
    parser.add_argument('--directorio', type=Path, required=True)
    parser.add_argument('--periodo', required=True)
    parser.add_argument('--salida', type=Path, required=True)
    parser.add_argument('--resultado', type=Path, required=True)
    parser.add_argument('--encoding', default='cp1252')
    return parser


def _buscar_origen(directorio: Path) -> Path | None:
    for nombre in ('dailoc.SF.txt', 'dailoc.sf.txt'):
        path = directorio / nombre
        if path.is_file():
            return path
    return None


def _normalizar_nombre(s: str) -> str:
    # Misma normalización usada por la liquidación de propietarios / DAILOC original.
    s = ''.join(
        c for c in unicodedata.normalize('NFKD', s)
        if not unicodedata.combining(c)
    )
    s = re.sub(r'[\\/:*?"<>|]', ' ', s)
    return re.sub(r'\s+', ' ', s).strip().title()


def _nombre_pdf(det) -> str:
    # Homónimo de la liquidación de propietario: mismo nombre y número,
    # cambiando únicamente el prefijo L por I.
    # Ej.: Zoireff Delia Susana I0000-00000242.pdf
    return f'{_normalizar_nombre(det.propietario)} I0000-{det.numero:08d}.pdf'


def _validaciones(detalles) -> list[dict]:
    salida: list[dict] = []
    for det in detalles:
        dif_imp = _diferencia(det.total_impuestos, det.total_impuestos_cobol)
        dif_prop = _diferencia(det.total_propietario, det.total_propietario_cobol)
        estado = 'OK' if dif_imp <= Decimal('0.01') and dif_prop <= Decimal('0.01') else 'DIFERENCIA'
        salida.append({
            'numero': det.numero,
            'cuenta': det.cuenta,
            'propietario': det.propietario,
            'paginas_cobol': det.paginas_cobol,
            'items': len(det.items),
            'total_impuestos_calculado': str(det.total_impuestos),
            'total_impuestos_cobol': str(det.total_impuestos_cobol),
            'diferencia_impuestos': str(dif_imp),
            'total_propietario_calculado': str(det.total_propietario),
            'total_propietario_cobol': str(det.total_propietario_cobol),
            'diferencia_propietario': str(dif_prop),
            'total_comision_iva_cobol': str(det.total_comision_iva_cobol),
            'estado': estado,
        })
    return salida


def _guardar_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + '\n',
        encoding='utf8',
    )


def main() -> None:
    args = _parser().parse_args()
    origen = _buscar_origen(args.directorio)
    if origen is None:
        print(f'No se encontró dailoc.SF.txt en {args.directorio}', file=sys.stderr)
        raise SystemExit(2)

    detalles = parsear_dailoc(origen, args.encoding)
    if not detalles:
        print('No se detectaron detalles DAILOC.', file=sys.stderr)
        raise SystemExit(2)

    periodos = {x.periodo_aaaamm for x in detalles if x.periodo_aaaamm}
    if len(periodos) != 1:
        print(f'Períodos DAILOC inconsistentes: {sorted(periodos)}', file=sys.stderr)
        raise SystemExit(2)

    periodo = next(iter(periodos))
    if periodo != args.periodo:
        print(
            f'El período solicitado ({args.periodo}) no coincide con DAILOC ({periodo}).',
            file=sys.stderr,
        )
        raise SystemExit(2)

    validaciones = _validaciones(detalles)
    pdfdir = args.salida / 'pdf'
    esperados = {_nombre_pdf(det) for det in detalles}
    existentes = sum(1 for nombre in esperados if (pdfdir / nombre).is_file())

    resumen = {
        'periodo': periodo,
        'archivo': origen.name,
        'detalles_detectados': len(detalles),
        'paginas_cobol': sum(x.paginas_cobol for x in detalles),
        'pdf_esperados': len(esperados),
        'pdf_existentes': existentes,
        'pdf_faltantes': max(0, len(esperados) - existentes),
        'pdf_generados': 0,
        'validaciones_ok': sum(1 for x in validaciones if x['estado'] == 'OK'),
        'validaciones_con_diferencia': sum(1 for x in validaciones if x['estado'] != 'OK'),
        'errores': 0,
        'validacion_ok': all(x['estado'] == 'OK' for x in validaciones),
    }

    if args.accion == 'analizar':
        _guardar_json(args.resultado, resumen)
        print(json.dumps(resumen, ensure_ascii=False, indent=2))
        return

    if not resumen['validacion_ok']:
        _guardar_json(args.resultado, resumen)
        print(json.dumps(resumen, ensure_ascii=False, indent=2))
        raise SystemExit(1)

    args.salida.mkdir(parents=True, exist_ok=True)
    pdfdir.mkdir(parents=True, exist_ok=True)

    errores: list[dict] = []
    generados = 0
    with (args.salida / 'detalles.jsonl').open('w', encoding='utf8') as jf:
        for det in detalles:
            dest = pdfdir / _nombre_pdf(det)
            try:
                generar_pdf_dailoc(det, dest, {})
                generados += 1
                jf.write(json.dumps(det.dict(), ensure_ascii=False) + '\n')
            except Exception as exc:
                errores.append({
                    'numero': det.numero,
                    'cuenta': det.cuenta,
                    'mensaje': str(exc),
                })

    # Limpiar únicamente PDFs obsoletos de esta carpeta DAILOC.
    for path in pdfdir.glob('*.pdf'):
        if path.name not in esperados:
            path.unlink()

    campos = list(validaciones[0].keys())
    with (args.salida / 'validacion.csv').open('w', encoding='utf8', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=campos)
        writer.writeheader()
        writer.writerows(validaciones)

    with (args.salida / 'errores.csv').open('w', encoding='utf8', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=['numero', 'cuenta', 'mensaje'])
        writer.writeheader()
        writer.writerows(errores)

    resumen.update({
        'pdf_existentes': generados,
        'pdf_faltantes': max(0, len(esperados) - generados),
        'pdf_generados': generados,
        'errores': len(errores),
        'validacion_ok': len(errores) == 0,
    })

    (args.salida / 'resumen.json').write_text(
        json.dumps(resumen, ensure_ascii=False, indent=2) + '\n',
        encoding='utf8',
    )
    _guardar_json(args.resultado, resumen)
    print(json.dumps(resumen, ensure_ascii=False, indent=2))

    raise SystemExit(1 if errores else 0)


if __name__ == '__main__':
    main()
